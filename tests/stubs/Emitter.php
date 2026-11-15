<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * Interface-only test shim for IRootFolder's legacy parent, absent from the OCP package.
 * Matches nextcloud/server stable34 lib/private/Hooks/Emitter.php.
 */

namespace OC\Hooks;

interface Emitter {
	public function listen($scope, $method, callable $callback);
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
