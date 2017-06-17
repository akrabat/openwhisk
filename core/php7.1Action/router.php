<?php
class RouterException extends RuntimeException {}

const SRC_DIR  = __DIR__ . '/src';
const ACTION_CONFIG_FILE = __DIR__. '/config.php';
const ACTION_SRC_FILENAME = 'index.php';
const TMP_ZIP_FILE = '/action.zip';
const ACTION_SRC_FILEPATH = SRC_DIR . '/' . ACTION_SRC_FILENAME;

$output = '';
try {
    switch ($_SERVER['REQUEST_URI']) {
        case '/init':
            // this end point is called once per container creation. It gives us the code we need
            // to run and the name of the function within that code that's the entry point. As PHP
            // has a setup/teardown model, we store the function name to a config file for retrieval
            // in the /run end point.
            $post = file_get_contents('php://input');
            $data = json_decode($post, true)['value'] ?? [];

            $name = $data['name'] ?? '';         // action name
            $main = $data['main'] ?? 'main';     // function to call (default: main)
            $code = $data['code'] ?? '';         // source code to run
            $binary = $data['binary'] ?? false;  // code is binary?

            if ($code) {
                if ($binary) {
                    // binary code is a zip file that's been base64 encoded, so unzip it
                    file_put_contents(TMP_ZIP_FILE, base64_decode($code));
                    $zip = new ZipArchive;
                    $res = $zip->open(TMP_ZIP_FILE);
                    if ($res !== true) {
                        throw new RuntimeException("Failed to open zip file: $res");
                    }
                    $res = $zip->extractTo(SRC_DIR . '/');
                    $zip->close();

                    // check that we have the file containing the entry point function
                    if (! file_exists(ACTION_SRC_FILEPATH)) {
                        throw new RuntimeException('Could not find ' . ACTION_SRC_FILENAME . ' in zip');
                    }
                } else {
                    file_put_contents(ACTION_SRC_FILEPATH, $code);
                }
            }

            // write config file
            $config = [
                'main' => $main,
                'name' => $name,
            ];
            $content = '<?php return ' . var_export($config, true) . ";\n";
            file_put_contents(ACTION_CONFIG_FILE, $content);

            // set output
            header('Content-Type: application/json');
            $output = json_encode(["OK" => true]);
            break;

        case '/run':
            // this end point is called once per action invocation. We load the function name from
            // the config file and then invoke it. Note that as PHP writes to php://output, we
            // capture in an output buffer and write the buffer to stdout for the OpenWhisk logs.
            if (! file_exists(ACTION_SRC_FILEPATH)) {
                error_log("NO ACTION FILE: " . ACTION_SRC_FILENAME);
                throw new RuntimeException("Could not find action file: " . ACTION_SRC_FILENAME);
            }

            // load config to collect function name to call
            $config = require ACTION_CONFIG_FILE;
            $functionName = $config['main'];
            
            // load Composer's autoloader
            require '/action/vendor/autoload.php';

            // load the action code in an output buffer just in case there's characters outside
            // opening and closing php tags
            ob_start();
            require ACTION_SRC_FILEPATH;
            ob_end_clean();

            // function arguments are in the POSTed data's "value" field
            $post = json_decode(file_get_contents('php://input'), true);
            $args = is_array($post['value']) ? $post['value'] : [];

            // run the action within an output buffer that writes php://output to stdout
            ob_start(function ($data) {
                file_put_contents("php://stdout", $data);
                return '';
            }, 1, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE | PHP_OUTPUT_HANDLER_REMOVABLE);
            $result = $functionName($args);
            ob_end_clean();

            // set output
            header('Content-Type: application/json');
            if (!is_array($result)) {
                error_log('Result must be an array but has type "' . gettype($result) . '":' . (string)$result);
                throw new RouterException("The action did not return a dictionary.", 502);
            }

            // cast result to an object for json_encode to ensure that an empty array becomes "{}"
            $output = json_encode((object)$result);

            // write out sentinels as we've finished all log output
            file_put_contents("php://stdout", "\nXXX_THE_END_OF_A_WHISK_ACTIVATION_XXX\n");
            file_put_contents("php://stderr", "XXX_THE_END_OF_A_WHISK_ACTIVATION_XXX\n");
            break;

        default:
            throw new RuntimeException('Unexpected call to ' . $_SERVER["REQUEST_URIp"]);
    }
} catch (Throwable $e) {
    error_log((string)$e);
    $code = $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    $message = $e instanceof RouterException ? $e->getMessage() : "Unknown error";
    
    file_put_contents("php://stdout", $message);
    http_response_code($code);
    $output = json_encode(['error' => $message]);

    // write out sentinels as we've finished all log output
    file_put_contents("php://stdout", "\nXXX_THE_END_OF_A_WHISK_ACTIVATION_XXX\n");
    file_put_contents("php://stderr", "XXX_THE_END_OF_A_WHISK_ACTIVATION_XXX\n");
}


// send response
header("Content-Length: " . mb_strlen($output));
echo $output;
