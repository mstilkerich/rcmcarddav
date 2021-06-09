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

window.rcmail && rcmail.addEventListener('init', function(evt) {
    if (rcmail.env.task == 'settings') {
        if (rcmail.gui_objects.addressbookslist) {
            rcmail.addressbooks_list = new rcube_treelist_widget(rcmail.gui_objects.addressbookslist, {
                selectable: true,
                tabexit: false,
                parent_focus: true,
                id_prefix: 'rcmli',
            });
        }
    }

    if (rcmail.env.action == 'plugin.carddav') {
        rcmail.register_command(
            'plugin.carddav-activate-abook',
            function(abookid) { rcmail.carddav_activate_abook(abookid, true); },
            true
        );
        rcmail.register_command(
            'plugin.carddav-deactivate-abook',
            function(abookid) { rcmail.carddav_activate_abook(abookid, false); },
            true
        );
    }
});

rcube_webmail.prototype.carddav_activate_abook = function(abookid, active)
{
    if (abookid) {
        // TODO
        //var prefix = state ? '' : 'un',
        //  lock = this.display_message('folder' + prefix + 'subscribing', 'loading');

        //this.http_post(prefix + 'subscribe', {_mbox: folder}, lock);
    }
};

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
