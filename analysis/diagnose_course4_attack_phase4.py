#!/usr/bin/env python3
"""Post-selection diagnostic only: joins fixed A5 and stored fixed-W4 scores."""
import csv, numpy as np
from pathlib import Path
O=Path(__file__).resolve().parent/'output'
def b(x):return str(x).lower()=='true'
def out(p,rows):
 with p.open('w',newline='',encoding='utf8') as h:
  w=csv.DictWriter(h,fieldnames=sorted({k for r in rows for k in r}));w.writeheader();w.writerows(rows)
def main():
 a={r['race_code']:r for r in csv.DictReader(open(O/'course4_attack_phase4_race_scores_20260401_20260930.csv',encoding='utf8'))};w={r['race_code']:r for r in csv.DictReader(open(O/'course34_wall_phase3_race_scores_20241001_20260930.csv',encoding='utf8'))}
 x=[]
 for k in a:
  if k in w:
   r={**a[k],'wall_probability':float(w[k]['wall_probability_candidate'])};r['attack_probability']=float(r['attack_probability_a5']);x.append(r)
 av=np.array([r['attack_probability'] for r in x]);wv=np.array([r['wall_probability'] for r in x]);ac=np.quantile(av,[1/3,2/3]);wc=np.quantile(wv,[1/3,2/3])
 for r in x:r['attack_tertile']=int(np.searchsorted(ac,r['attack_probability']))+1;r['wall_tertile']=int(np.searchsorted(wc,r['wall_probability']))+1
 cross=[]
 for i in range(1,4):
  for j in range(1,4):
   z=[r for r in x if r['wall_tertile']==i and r['attack_tertile']==j];cross.append({'wall_tertile':i,'attack_tertile':j,'n':len(z),'four_win_attack_rate_pct':100*np.mean([b(r['four_win_attack']) for r in z]),'four_first_rate_pct':100*np.mean([b(r['four_first']) for r in z]),'four_top2_rate_pct':100*np.mean([b(r['four_top2']) for r in z]),'four_top3_rate_pct':100*np.mean([b(r['four_top3']) for r in z])})
 # Score bands use exposed-score quantiles only for a descriptive diagnostic.
 q=np.quantile(av,[.2,.4,.6,.8]);bands=[]
 for i,(lo,hi) in enumerate(((0,19),(20,39),(40,59),(60,79),(80,100))):
  z=[r for r in x if np.searchsorted(q,r['attack_probability'])==i];first=[b(r['four_first']) for r in z];atk=[b(r['four_win_attack']) for r in z];bands.append({'score_band':f'{lo}-{hi}','n':len(z),'four_win_attack_rate_pct':100*np.mean(atk),'four_first_non_attack_rate_pct':100*(np.mean(first)-np.mean(atk)),'four_first_rate_pct':100*np.mean(first),'four_top2_rate_pct':100*np.mean([b(r['four_top2']) for r in z]),'four_top3_rate_pct':100*np.mean([b(r['four_top3']) for r in z])})
 venues=[]
 for v in sorted({r['stadium'] for r in x}):
  z=[r for r in x if r['stadium']==v];lo=[r for r in z if r['attack_tertile']==1];hi=[r for r in z if r['attack_tertile']==3];venues.append({'stadium':v,'n':len(z),'low_attack_n':len(lo),'high_attack_n':len(hi),'low_attack_rate_pct':100*np.mean([b(r['four_win_attack']) for r in lo]),'high_attack_rate_pct':100*np.mean([b(r['four_win_attack']) for r in hi]),'difference_pct_point':100*(np.mean([b(r['four_win_attack']) for r in hi])-np.mean([b(r['four_win_attack']) for r in lo]))})
 out(O/'course4_attack_phase4_wall_attack_cross_20260401_20260930.csv',cross);out(O/'course4_attack_phase4_score_bands_20260401_20260930.csv',bands);out(O/'course4_attack_phase4_venues_20260401_20260930.csv',venues);out(O/'course4_attack_phase4_full_scores_20260401_20260930.csv',x)
 print('joined',len(x),'spearman',np.corrcoef(np.argsort(np.argsort(av)),np.argsort(np.argsort(wv)))[0,1]);print(cross);print(bands);print('venues high>low',sum(r['high_attack_rate_pct']>r['low_attack_rate_pct'] for r in venues),len(venues))
if __name__=='__main__':main()
