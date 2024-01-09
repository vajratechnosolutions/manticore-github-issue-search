<?php declare(strict_types=1);

/**
 * @route api/subscribe/([^/]+)/([^/]+): org, name
 * @var string $org
 * @var string $name
 * @var string $email
 */

use App\Component\Notification;
use App\Component\Search;

/** @var App\Model\Repo $repo */
$repo = result(Search::getRepo($org, $name));
return Notification::subscribe($repo, $email);
