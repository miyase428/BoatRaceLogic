<?php
//require_once __DIR__ . '/controllers/IndexController.php';
require_once __DIR__ . '/../controllers/IndexController.php';

$controller = new IndexController();
$viewData   = $controller->handle();

extract($viewData); // $selected_date, $selected_place, $race_code などを展開

include __DIR__ . '/views/index_view.php';
