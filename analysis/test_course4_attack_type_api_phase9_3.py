#!/usr/bin/env python3
import json,os,subprocess,tempfile
from datetime import datetime,timedelta
from pathlib import Path
from zoneinfo import ZoneInfo
ROOT=Path(__file__).resolve().parent.parent
def call(race,env={}):
 e=os.environ|env
 return json.loads(subprocess.check_output(['php','-r',f'$_GET=["race_code"=>"{race}"]; include "web/course4_attack_type_api.php";'],cwd=ROOT,text=True,env=e,stderr=subprocess.DEVNULL))
def main():
 checks={'invalid_race':call('bad')['error_code']=='INVALID_RACE_CODE'}
 with tempfile.TemporaryDirectory() as d:
  deadline_root=Path(d)/'deadlines';deadline_root.mkdir()
  day=(datetime.now(ZoneInfo('Asia/Tokyo'))+timedelta(days=1)).strftime('%Y%m%d');race=day+'WKM12'
  (deadline_root/f'deadlines_{day}.json').write_text(json.dumps({'deadlines':{race:'23:59'}}))
  guarded_env={'TENKAI_AI_DEADLINE_ROOT':str(deadline_root)}
  for name,body,code,want in [('bad','print("{")',0,'INFERENCE_MALFORMED_RESPONSE'),('exit','import sys;sys.exit(3)',3,'INFERENCE_PROCESS_FAILED')]:
   p=Path(d)/name;p.write_text('#!/usr/bin/env python3\n'+body);p.chmod(0o755);checks[name]=call(race,guarded_env|{'COURSE4_API_TEST_PYTHON':str(p)})['error_code']==want
  p=Path(d)/'slow';p.write_text('#!/usr/bin/env python3\nimport time;time.sleep(16)');p.chmod(0o755);checks['timeout']=call(race,guarded_env|{'COURSE4_API_TEST_PYTHON':str(p)})['error_code']=='INFERENCE_TIMEOUT'
 if not all(checks.values()):raise SystemExit(str(checks))
 print(json.dumps({'pass':True,'checks':checks}))
if __name__=='__main__':main()
