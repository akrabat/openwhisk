# Python proxy to run PHP actions.
#
# Licensed to the Apache Software Foundation (ASF) under one or more
# contributor license agreements.  See the NOTICE file distributed with
# this work for additional information regarding copyright ownership.
# The ASF licenses this file to You under the Apache License, Version 2.0
# (the "License"); you may not use this file except in compliance with
# the License.  You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.
#

import os
import glob
import sys
import subprocess
import codecs
import json
sys.path.append('../actionProxy')
from actionproxy import ActionRunner, main, setRunner  # noqa


DEST_SCRIPT_FILE = '/action/runner.php'
DEST_BIN_FILE = '/usr/local/bin/php'

class PhpRunner(ActionRunner):

    def __init__(self):
        ActionRunner.__init__(self, DEST_SCRIPT_FILE, DEST_BIN_FILE)

    # @return True iff binary exists and is executable, False otherwise
    def verify(self):
        return (os.path.isfile(self.source) and
                os.access(self.source, os.R_OK))

    def env(self, message):
        env = ActionRunner.env(self, message)
        args = message.get('value', {}) if message else {}
        env['WHISK_INPUT'] = json.dumps(args)
        return env


    # runs the action, called iff self.verify() is True.
    # @param args is a JSON object representing the input to the action
    # @param env is the environment for the action to run in (defined edge
    # host, auth key)
    # return JSON object result of running the action or an error dictionary
    # if action failed
    def run(self, args, env):
        def error(msg):
            # fall through (exception and else case are handled the same way)
            sys.stdout.write('%s\n' % msg)
            return (502, {'error': 'The action did not return a dictionary.'})

        try:
            input = json.dumps(args)

            p = subprocess.Popen(
                ["/usr/local/bin/php", "/action/runner.php", input],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=env)
        except Exception as e:
            return error(e)

        # run the process and wait until it completes.
        # stdout/stderr will always be set because we passed PIPEs to Popen
        o, e = p.communicate()

        # stdout/stderr may be either text or bytes, depending on Python
        # version, so if bytes, decode to text. Note that in Python 2
        # a string will match both types; so also skip decoding in that case
        if isinstance(o, bytes) and not isinstance(o, str):
            o = o.decode('utf-8')
        if isinstance(e, bytes) and not isinstance(e, str):
            e = e.decode('utf-8')

        # get the last line of stdout, even if empty
        lastNewLine = o.rfind('\n', 0, len(o)-1)
        if lastNewLine != -1:
            # this is the result string to JSON parse
            lastLine = o[lastNewLine+1:].strip()
            # emit the rest as logs to stdout (including last new line)
            sys.stdout.write(o[:lastNewLine+1])
        else:
            # either o is empty or it is the result string
            lastLine = o.strip()

        if e:
            sys.stderr.write(e)

        try:
            json_output = json.loads(lastLine)
            if isinstance(json_output, dict):
                return (200, json_output)
            else:
                return error(lastLine)
        except Exception:
            return error(lastLine)


if __name__ == '__main__':
    setRunner(PhpRunner())
    main()
