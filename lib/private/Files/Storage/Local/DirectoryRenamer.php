<?php

/**
 * @copyright Copyright (c) 2020, Jakub Gawron <kubatek94@gmail.com>
 *
 * @author Jakub Gawron <kubatek94@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Files\Storage\Local;

use OC\Files\Storage\Local\CrossDeviceLinkException;

/**
 * Support class for Local Storage, responsible only for handling directory renames.
 * It attempts to perform normal rename, 
 * but fallbacks to copy and unlink strategy when it fails due to "cross-device link not permitted" error. 
 */
class DirectoryRenamer {
    const STATE_NO_ERROR = 0;
    const STATE_CAUGHT_RENAME_WARNING = 1;

    /**
     * @var callable
     */
    private $fallbackHandler;

    /**
     * @param callable $fallbackHandler Copy and rename strategy to use when normal rename fails 
     */
    public function __construct(callable $fallbackHandler) {
        $this->fallbackHandler = $fallbackHandler;
    }

    /**
     * Rename a file or directory
     * 
     * @param string $oldname
     * @param string $newname
     * @return bool
     */
    public function rename(string $oldname, string $newname): bool {
        $this->setupErrorHandler();

		try {
			return rename($oldname, $newname);
		} catch (CrossDeviceLinkException $e) {
            return ($this->fallbackHandler)();
        }
        
        return false;
    }

    /**
     * Sets a temporary error handler that covers the cross-device link error with a state machine.
     * @throws CrossDeviceLinkException
     */
    private function setupErrorHandler(): void {
        $state = self::STATE_NO_ERROR;

        set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$state) {
            // when we hit first warning, we'll check if it's what we expect
            // if it is, we transition to next state and return
            // otherwise we continue
            switch ($state) {
                case self::STATE_NO_ERROR:
                    if ($errstr === "rename(): The first argument to copy() function cannot be a directory") {
                        $state = self::STATE_CAUGHT_RENAME_WARNING;
                        return;
                    }
            }

            // when we hit second warning, or a first warning that wasn't the above expected warning
            // we'll check if it is the cross-device link and if so, we throw the exception
            // to let the caller know to use the fallback strategy
            switch ($state) {
                case self::STATE_NO_ERROR:
                case self::STATE_CAUGHT_RENAME_WARNING:
                    if (static::endsWith($errstr, "cross-device link")) {
                        restore_error_handler();
                        throw new CrossDeviceLinkException();
                    }
            }

            // if we get to this point, we got called with warnings which we can't handle (or not in the order anticipated)
            // so we restore the previous error handler and return false to let it handle that error
            restore_error_handler();
            return false;
		}, E_WARNING);
    }

    /**
     * Check if $haystack ends with $needle
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    private static function endsWith(string $haystack, string $needle): bool {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }

        return (substr($haystack, -$length) === $needle);
    }
}