<?php

class SlitLogic
{
    public function getFeatureNames(): array
    {
        return [
            "wall_none"      => "壁なし",
            "middle_attack"  => "3号艇攻め",
            "dash_fast"      => "ダッシュ先行",
            "inside_fast"    => "スロー先行",
            "inside_late"    => "1号艇遅れ",
            "line_abreast"   => "横一線",
            "two_three_late" => "2・3号艇遅れ",
            "middle_hollow"  => "中凹み",
            "middle_bulge"   => "中ぶくれ",
            "one_two_fast"   => "1・2先行",
            "outside_attack" => "外側先行",
        ];
    }
}
