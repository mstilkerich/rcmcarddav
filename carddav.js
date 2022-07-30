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
            rcmail.addressbooks_list.addEventListener('select', function(node) { rcmail.carddav_ablist_select(node); });
        }
    }

    if (rcmail.env.action == 'plugin.carddav') {
        rcmail.register_command(
            'plugin.carddav-toggle-abook-active',
            function(props) { rcmail.carddav_activate_abook(props.abookid, props.state); },
            true
        );
        rcmail.register_command(
            'carddav-create-account',
            function() { rcmail.carddav_create_account(); },
            true
        );
        rcmail.register_command(
            'carddav-delete-account',
            function() { rcmail.carddav_delete_account(); },
            false
        );
    } else if (rcmail.env.action == 'plugin.carddav.abookdetails') {
        rcmail.register_command(
            'plugin.carddav-save-abook',
            function() { rcmail.carddav_save_abook(); },
            true // enable
        );
    } else if (rcmail.env.action == 'plugin.carddav.accountdetails') {
        rcmail.register_command(
            'plugin.carddav-save-account',
            function() { rcmail.carddav_save_account(); },
            true // enable
        );
    }
});

// handler when a row (account/addressbook) of the list is selected
rcube_webmail.prototype.carddav_ablist_select = function(node)
{
    var id = node.id, url, win;

    if (id.startsWith("_acc")) {
        // Account
        url = '&_action=plugin.carddav.accountdetails&accountid=' + id.substr(4);
        this.enable_command('carddav-delete-account', true);
    } else if (id.startsWith("_abook")) {
        // Addressbook
        url = '&_action=plugin.carddav.abookdetails&abookid=' + id.substr(6);
        this.enable_command('carddav-delete-account', false);
    } else {
        this.enable_command('carddav-delete-account', false);
        // unexpected id
        return;
    }

    if (win = this.get_frame_window(this.env.contentframe)) {
        if (!url) {
            if (win.location && win.location.href.indexOf(this.env.blankpage) < 0) {
                win.location.href = this.env.blankpage;
            }
            if (this.env.frame_lock) {
                this.set_busy(false, null, this.env.frame_lock);
            }
            return;
        }

        this.env.frame_lock = this.set_busy(true, 'loading');
        win.location.href = this.env.comm_path + '&_framed=1' + url;
    }
};

rcube_webmail.prototype.carddav_activate_abook = function(abookid, active)
{
    if (abookid) {
        var prefix = active ? '' : 'de';
        var lock = this.display_message(rcmail.get_label('carddav.' + prefix + 'activatingabook', 'loading'));

        this.http_post("plugin.carddav.activateabook", {abookid: abookid, state: (active ? 1 : 0)}, lock);
    }
};

// resets state of addressbook active checkbox (e.g. on error)
rcube_webmail.prototype.carddav_reset_active = function(abook, state)
{
    var row = rcmail.addressbooks_list.get_item(abook, true);
    if (row) {
        $('input[name="_active[]"]', row).first().prop('checked', state);
    }
};

// reloads the page
rcube_webmail.prototype.carddav_redirect = function(target)
{
    (this.is_framed() ? parent : window).location.reload();
};

rcube_webmail.prototype.carddav_save_abook = function()
{
    $('form[name="addressbookdetails"]').submit();
};

rcube_webmail.prototype.carddav_save_account = function()
{
    $('form[name="accountdetails"]').submit();
};

// this is called when the Add Account button is clicked
rcube_webmail.prototype.carddav_create_account = function()
{
    var win;
    if (win = this.get_frame_window(this.env.contentframe)) {
        this.env.frame_lock = this.set_busy(true, 'loading');
        win.location.href = this.env.comm_path + '&_framed=1&_action=plugin.carddav.accountdetails&accountid=new';
    }
};

// this is called when the Delete Account button is clicked
rcube_webmail.prototype.carddav_delete_account = function()
{
    var win;

    var selectedNode = rcmail.addressbooks_list.get_selection();
    if (selectedNode.startsWith("_acc")) {
        if (win = this.get_frame_window(this.env.contentframe)) {
            this.env.frame_lock = this.set_busy(true, 'loading');
            win.location.href = this.env.comm_path +
                '&_framed=1&_action=plugin.carddav.delete-account&accountid=' + selectedNode.substr(4);
        }
    }
};

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
