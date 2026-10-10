<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2022 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
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

namespace MStilkerich\Tests\RCMCardDAV;

use PHPUnit\Framework\TestCase;
use MStilkerich\RCMCardDAV\Frontend\RcmInterface;

/**
 * @psalm-import-type RcMsgType from RcmInterface
 */
final class RcmAdapterStub implements RcmInterface
{
    /** @var array<string,string> Simulated POST data */
    public $getInputs = [];

    /** @var array<string,string> Simulated POST data */
    public $postInputs = [];

    /** @var array<string, callable> Records installed hook functions */
    public $hooks = [];

    /** @var list<list{RcMsgType,string}> */
    private $shownMessages = [];

    /** @var list<string> */
    public $sentTemplates = [];

    /** @var list<list{string,array}> */
    public $sentCommands = [];

    #[\Override]
    public function locText(string $msgId, array $vars = []): string
    {
        return $msgId;
    }

    #[\Override]
    public function inputValue(string $id, bool $allowHtml, int $source = \rcube_utils::INPUT_POST): ?string
    {
        if ($source === \rcube_utils::INPUT_POST) {
            return $this->postInputs[$id] ?? null;
        } elseif ($source === \rcube_utils::INPUT_GET) {
            return $this->getInputs[$id] ?? null;
        } elseif ($source === \rcube_utils::INPUT_GP) {
            return $this->postInputs[$id] ?? $this->getInputs[$id] ?? null;
        } else {
            TestCase::assertFalse(true, "unsupported input source for inputValue() test stub");
        }

        return null;
    }

    #[\Override]
    public function showMessage(string $msg, string $msgType = 'notice', $override = true, $timeout = 0): void
    {
        $this->shownMessages[] = [ $msgType, $msg ];
    }

    #[\Override]
    public function clientCommand(string $method, ...$arguments): void
    {
        $this->sentCommands[] = [ $method, $arguments ];
    }

    #[\Override]
    public function addHook(string $hook, callable $callback): void
    {
        // currently this stub only supports one callback per hook
        TestCase::assertFalse(isset($this->hooks[$hook]), "Duplicate hook $hook");
        $this->hooks[$hook] = $callback;
    }

    #[\Override]
    public function registerAction(string $action, callable $callback): void
    {
    }

    #[\Override]
    public function addTexts(string $dir): void
    {
    }

    #[\Override]
    public function includeCSS(string $cssFile): void
    {
    }

    #[\Override]
    public function includeJS(string $jsFile, bool $rcInclude = false): void
    {
    }

    #[\Override]
    public function addGuiObject(string $obj, string $id): void
    {
    }

    #[\Override]
    public function setPageTitle(string $title): void
    {
    }

    #[\Override]
    public function addTemplateObjHandler(string $name, callable $func): void
    {
    }

    #[\Override]
    public function sendTemplate(string $templ, $exit = true): void
    {
        $this->sentTemplates[] = $templ;
    }

    /**
     * @param array<string,string> $attrib
     */
    #[\Override]
    public function requestForm(array $attrib, string $content): string
    {
        $attrs = '';
        foreach ($attrib as $k => $v) {
            $attrs .= " $k='$v'";
        }

        return "<form $attrs>$content</form>";
    }

    #[\Override]
    public function setEnv(string $name, $value, bool $addToJs = true): void
    {
    }

    /**
     * Checks if a message containing the given string with the given message type was sent to the client.
     * @param RcMsgType $msgType
     */
    public function checkShownMessages(string $msgType, string $msg): bool
    {
        foreach ($this->shownMessages as [$mT,$m]) {
            if ($msgType === $mT && strpos($m, $msg) !== false) {
                return true;
            }
        }

        return false;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
