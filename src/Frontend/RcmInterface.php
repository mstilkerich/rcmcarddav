<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2021 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
 *                         Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of RCMCardDAV.
 *
 * RCMCardDAV is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * RCMCardDAV is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RCMCardDAV. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube\Frontend;

use MStilkerich\CardDavClient\{Account, AddressbookCollection};
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook, Config};

/**
 * Adapter interface to roundcube APIs.
 *
 * This allows us to decouple the dependencies from roundcube to a large extent (except for where be subclass roundcube
 * classes) and therefore improves testability.
 */
interface RcmInterface
{
    /**
     * Returns a localized text string.
     *
     * @param string $msgId The identifier of the text.
     * @param array<string,string> $vars Variables to replace in the text.
     * @return string The localized text string.
     */
    public function locText(string $msgId, array $vars = []): string;

    /**
     * Gets a POSTed input value.
     *
     * @param string $id Form identifier of the field.
     * @param bool $allowHtml Whether to allow HTML tags in the input. If false, HTML tags will be stripped.
     * @param \rcube_utils::INPUT_* $source
     * @return ?string Field value or NULL if not available.
     */
    public function inputValue(string $id, bool $allowHtml, int $source = \rcube_utils::INPUT_POST): ?string;

    /**
     * Shows a message to the roundcube user.
     * @param string $msg The message.
     * @param 'notice'|'confirmation'|'error'|'warning' $msgType
     * @param bool $override Override last set message
     * @param int $timeout Message displaying time in seconds
     */
    public function showMessage(string $msg, string $msgType = 'notice', $override = true, $timeout = 0): void;

    /**
     * Call a client (javascript) method
     *
     * @param string           $method       Method to call
     * @param string|bool|int  ...$arguments Additional arguments
     */
    public function clientCommand(string $method, ...$arguments): void;

    /**
     * Installs a roundcube hook function.
     */
    public function addHook(string $hook, callable $callback): void;

    /**
     * Register a handler for a specific client-request action
     *
     * The callback will be executed upon a request like /?_task=mail&_action=plugin.myaction
     *
     * @param string   $action   Action name (should be unique)
     * @param callable $callback Callback function
     */
    public function registerAction(string $action, callable $callback): void;

    /**
     * Loads localized texts for the current locale.
     */
    public function addTexts(string $dir): void;

    /**
     * Includes a CSS on the page.
     *
     * @param string $cssFile Path to CSS file relative to the plugin's skin path.
     */
    public function includeCSS(string $cssFile): void;

    /**
     * Includes a javascript file on the page.
     *
     * @param string $jsFile Path to JS file relative to the plugin's skin path or roundcube standard JS paths.
     * @param bool $rcInclude If true, the script to include is taken from the roundcube path, not the plugin.
     */
    public function includeJS(string $jsFile, bool $rcInclude = false): void;

    /**
     * Register a GUI object to the client script
     *
     * @param string $obj Object name
     * @param string $id  Object ID
     */
    public function addGuiObject(string $obj, string $id): void;

    /**
     * Setter for page title
     *
     * @param string $title Page title
     */
    public function setPageTitle(string $title): void;

        /**
     * Register a template object handler
     *
     * @param string $name Object name
     * @param callable $func Function to call
     * @psalm-param callable(array{id?: string}):string $func
     */
    public function addTemplateObjHandler(string $name, callable $func): void;

    /**
     * Send the request output to the client.
     * This will either parse a skin template.
     *
     * @param string $templ Template name
     * @param bool   $exit  True if script should terminate (default)
     */
    public function sendTemplate(string $templ, $exit = true): void;

    /**
     * Build a form tag with a unique request token
     *
     * @param array  $attrib  Named tag parameters including 'action' and 'task' values which are put into hidden fields
     * @param string $content Form content
     *
     * @return string HTML code for the form
     */
    public function requestForm(array $attrib, string $content): string;
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
