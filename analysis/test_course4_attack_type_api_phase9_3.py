#!/usr/bin/env python3
import json,os,subprocess,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
def call(race,env={}):
 e=os.environ|env
 return json.loads(subprocess.check_output(['php','-r',f'$_GET=["race_code"=>"{race}"]; include "web/course4_attack_type_api.php";'],cwd=ROOT,text=True,env=e,stderr=subprocess.DEVNULL))
def main():
 checks={'invalid_race':call('bad')['error_code']=='INVALID_RACE_CODE'}
 with tempfile.TemporaryDirectory() as d:
  for name,body,code,want in [('bad','print("{")',0,'INFERENCE_MALFORMED_RESPONSE'),('exit','import sys;sys.exit(3)',3,'INFERENCE_PROCESS_FAILED')]:
   p=Path(d)/name;p.write_text('#!/usr/bin/env python3\n'+body);p.chmod(0o755);checks[name]=call('20261008WKM12',{'COURSE4_API_TEST_PYTHON':str(p)})['error_code']==want
  p=Path(d)/'slow';p.write_text('#!/usr/bin/env python3\nimport time;time.sleep(16)');p.chmod(0o755);checks['timeout']=call('20261008WKM12',{'COURSE4_API_TEST_PYTHON':str(p)})['error_code']=='INFERENCE_TIMEOUT'
 if not all(checks.values()):raise SystemExit(str(checks))
 print(json.dumps({'pass':True,'checks':checks}))
if __name__=='__main__':main()
