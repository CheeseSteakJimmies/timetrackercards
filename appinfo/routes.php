<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		['name' => 'timer#index', 'url' => '/api/timers', 'verb' => 'GET'],
		['name' => 'timer#create', 'url' => '/api/timers', 'verb' => 'POST'],
		['name' => 'timer#setOrder', 'url' => '/api/timers/order', 'verb' => 'POST'],
		['name' => 'timer#update', 'url' => '/api/timers/{id}', 'verb' => 'PUT'],
		['name' => 'timer#delete', 'url' => '/api/timers/{id}', 'verb' => 'DELETE'],
		['name' => 'timer#start', 'url' => '/api/timers/{id}/start', 'verb' => 'POST'],
		['name' => 'timer#pause', 'url' => '/api/timers/{id}/pause', 'verb' => 'POST'],
		['name' => 'timer#reset', 'url' => '/api/timers/{id}/reset', 'verb' => 'POST'],
		['name' => 'timer#leave', 'url' => '/api/timers/{id}/leave', 'verb' => 'POST'],
		['name' => 'timer#invite', 'url' => '/api/timers/{id}/members', 'verb' => 'POST'],
		['name' => 'timer#removeMember', 'url' => '/api/timers/{id}/members/{uid}', 'verb' => 'DELETE'],

		['name' => 'timer#searchUsers', 'url' => '/api/users', 'verb' => 'GET'],
	],
];
