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

/* global $, rcmail, rcube_webmail, rcube_treelist_widget, UI, parent */

window.rcmail && rcmail.addEventListener('init', function (evt) {
  if (rcmail.env.task !== 'settings') {
    return
  }

  if (rcmail.env.action === 'plugin.carddav') {
    if (rcmail.gui_objects.addressbookslist) {
      // eslint-disable-next-line new-cap
      rcmail.addressbooks_list = new rcube_treelist_widget(rcmail.gui_objects.addressbookslist, {
        selectable: true,
        tabexit: false,
        parent_focus: true,
        id_prefix: 'rcmli'
      })
      rcmail.addressbooks_list.addEventListener('select', function (node) { rcmail.carddav_AbListSelect(node) })
    }

    rcmail.register_command(
      'plugin.carddav-AbToggleActive',
      function (abookid, active) { rcmail.carddav_AbToggleActive(abookid, active) },
      true
    )

    // don't show the Add account button if disabled by the admin
    if (rcmail.env.carddav_forbidCustomAddressbooks) {
      $('.carddav_AccAdd').hide()
    } else {
      rcmail.register_command('plugin.carddav-AccAdd', function () { rcmail.carddav_AccAdd() }, true)
    }

    rcmail.register_command('plugin.carddav-AccRm', function () { rcmail.carddav_AccRm() }, false)
    rcmail.register_command('plugin.carddav-AccRedisc', function () { rcmail.carddav_AccRedisc() }, false)
    rcmail.register_command('plugin.carddav-AbSync', function () { rcmail.carddav_AbSync('AbSync') }, false)
    rcmail.register_command('plugin.carddav-AbClrCache', function () { rcmail.carddav_AbSync('AbClrCache') }, false)
  } else if (rcmail.env.action === 'plugin.carddav.AbDetails') {
    rcmail.register_command(
      'plugin.carddav-AbSave',
      function () { rcmail.carddav_AccAbSave('addressbookdetails', 'plugin.carddav.AbSave') },
      true // enable
    )
  } else if (rcmail.env.action === 'plugin.carddav.AccDetails') {
    const action = $('input[name="accountid"]').val() == "new" ? 'plugin.carddav.AccAdd' : 'plugin.carddav.AccSave'
    rcmail.register_command(
      'plugin.carddav-AccSave',
      function () { rcmail.carddav_AccAbSave('accountdetails', action) },
      true // enable
    )
  }
})

// handler when a row (account/addressbook) of the list is selected
rcube_webmail.prototype.carddav_AbListSelect = function (node) {
  const id = node.id
  let url

  this.enable_command('plugin.carddav-AccRm', false)
  this.enable_command('plugin.carddav-AccRedisc', false)
  this.enable_command('plugin.carddav-AbSync', false)
  this.enable_command('plugin.carddav-AbClrCache', false)

  if (id.startsWith('_acc')) {
    // Account
    url = '&_action=plugin.carddav.AccDetails&accountid=' + id.substr(4)
    this.enable_command('plugin.carddav-AccRm', !node.classes.includes('preset'))
    this.enable_command('plugin.carddav-AccRedisc', true)
  } else if (id.startsWith('_abook')) {
    // Addressbook
    url = '&_action=plugin.carddav.AbDetails&abookid=' + id.substr(6)
    this.enable_command('plugin.carddav-AbSync', true)
    this.enable_command('plugin.carddav-AbClrCache', true)
  } else {
    // unexpected id
    return
  }

  const win = this.get_frame_window(this.env.contentframe)
  if (win) {
    this.env.frame_lock = this.set_busy(true, 'loading')
    win.location.href = this.env.comm_path + '&_framed=1' + url
  }
}

// handler invoked when the toggle-active checkbox for an addressbook is changed
rcube_webmail.prototype.carddav_AbToggleActive = function (abookid, active) {
  if (abookid) {
    const lock = this.display_message('', 'loading')
    this.http_post('plugin.carddav.AbToggleActive', { abookid, active: (active ? '1' : '0') }, lock)
  }
}

// resets state of addressbook active checkbox (e.g. on error), invoked from the backend
rcube_webmail.prototype.carddav_AbResetActive = function (abook, active) {
  const row = rcmail.addressbooks_list.get_item('_abook' + abook, true)
  if (row) {
    $('input[name="_active[]"]', row).first().prop('checked', active)
  }
}

// invoked when the Save button in the account or addressbook detail view is pressed
rcube_webmail.prototype.carddav_AccAbSave = function (formname, action) {
  const lock = this.display_message('', 'loading')
  const formDataTuples = $('form[name="' + formname + '"]').serializeArray()
  const formData = {}
  for (const tuple of formDataTuples) {
    formData[tuple.name] = tuple.value
  }

  this.http_post(action, formData, lock)
}

/**
 * Updates the fields of a form shown in the content frame and related elements in the addressbook list.
 *
 * This function is invoked both from the content frame (e.g., AbSave) as well as the main frame (e.g. AbSync) and must
 * handle both cases.
 *
 * @param {Object} formData The data fields to update in the form and the addressbooks list.
 */
rcube_webmail.prototype.carddav_UpdateForm = function (formData) {
  const win = this.is_framed() ? window : this.get_frame_window(this.env.contentframe)
  for (const fieldKey in formData) {
    const fieldType = formData[fieldKey][0]
    const fieldValue = formData[fieldKey][1]

    const inputSelectorByName = 'input[name="' + fieldKey + '"]'
    let node, nodeUpdate

    switch (fieldType) {
      case 'text':
      case 'timestr':
      case 'password':
        $(inputSelectorByName, win.document).val(fieldValue)
        break

      case 'radio':
        $(inputSelectorByName + '[value="' + fieldValue + '"]', win.document).prop('checked', true)
        break

      case 'datetime':
      case 'plain':
        $('span#rcmcrd_plain_' + fieldKey, win.document).text(fieldValue)
        break

      // this is a special case to update an element given by a CSS selector in the parent document, i.e. update the
      // name in the addressbook list.
      case 'parent':
        node = $('#rcmli' + fieldKey + ' > a', win.parent.document)
        node.text(fieldValue)
        nodeUpdate = { html: node }

        win.parent.window.rcmail.addressbooks_list.update(fieldKey, nodeUpdate, true)
        break
    }
  }
}

// invoked from the backend to insert new accounts or addressbooks in the list
// records is an array of arrays, of which each has the members:
// [0]: object id
// [1]: li HTML code
// [2]: parent id (null for accounts, account id for addressbooks)
//
// If selectId is specified as an array of object type (acc or abook) and object id, the so specified item is selected
rcube_webmail.prototype.carddav_InsertListElem = function (records, selectId) {
  for (const record of records) {
    const [id, newLi, accountId] = record
    let type, classes
    let domIdParent = null

    if (accountId === undefined) {
      type = 'acc'
      classes = ['account']
    } else {
      type = 'abook'
      classes = ['addressbook']
      domIdParent = '_acc' + accountId
    }
    const domId = '_' + type + id

    parent.window.rcmail.addressbooks_list.insert(
      { id: domId, html: newLi, classes },
      domIdParent,
      true
    )

    // fixup the checkboxes (note: this is elastic-specific)
    if (typeof UI === 'object' && typeof UI.pretty_checkbox === 'function') {
      $('#rcmli' + domId + ' input[type="checkbox"]', parent.document).each(function () { UI.pretty_checkbox(this) })
    }
  }

  if (selectId !== undefined) {
    const [type, id] = selectId
    const domId = '_' + type + id
    parent.window.rcmail.addressbooks_list.select(domId)
  }
}

// this is called when the Add Account button is clicked
rcube_webmail.prototype.carddav_AccAdd = function () {
  const win = this.get_frame_window(this.env.contentframe)
  if (win) {
    this.env.frame_lock = this.set_busy(true, 'loading')
    win.location.href = this.env.comm_path + '&_framed=1&_action=plugin.carddav.AccDetails&accountid=new'
  }
}

// this is called when the Delete Account button is clicked
rcube_webmail.prototype.carddav_AccRm = function () {
  const selectedNode = rcmail.addressbooks_list.get_selection()
  if (selectedNode.startsWith('_acc')) {
    const accountid = selectedNode.substr(4)
    const lock = this.display_message('', 'loading')
    this.http_post('plugin.carddav.AccRm', { accountid }, lock)
  }
}

// this is called when the Rediscover Account button is clicked
rcube_webmail.prototype.carddav_AccRedisc = function () {
  const selectedNode = rcmail.addressbooks_list.get_selection()
  if (selectedNode.startsWith('_acc')) {
    const accountid = selectedNode.substr(4)
    const lock = this.display_message('', 'loading')
    this.http_post('plugin.carddav.AccRedisc', { accountid }, lock)
  }
}

// invoked from the backend to remove accounts or addressbooks from the addressbook list
rcube_webmail.prototype.carddav_RemoveListElem = function (accountId, abookIds) {
  if (abookIds === undefined) {
    // remove the entire account
    const domIdAcc = '_acc' + accountId
    parent.window.rcmail.addressbooks_list.remove(domIdAcc)
  } else {
    // remove only the given abooks
    for (const abookId of abookIds) {
      const domIdAbook = '_abook' + abookId
      parent.window.rcmail.addressbooks_list.remove(domIdAbook)
    }
  }

  // if the selected node was removed in the process, set the content frame to the blank page
  const selectedNode = rcmail.addressbooks_list.get_selection()
  if (selectedNode && rcmail.addressbooks_list.get_node(selectedNode) === undefined) {
    const win = this.get_frame_window(this.env.contentframe)
    if (win) {
      win.location.href = this.env.blankpage
    }
  }
}

// this is called when the Resync addressbook button is hit
// synctype: AbSync, AbClrCache
rcube_webmail.prototype.carddav_AbSync = function (synctype) {
  const selectedNode = rcmail.addressbooks_list.get_selection()
  if (selectedNode.startsWith('_abook')) {
    const abookid = selectedNode.substr(6)
    const lock = this.display_message(rcmail.get_label(synctype + '_msg_inprogress', 'carddav'), 'loading')
    this.http_post('plugin.carddav.AbSync', { abookid, synctype }, lock)
  }
}

// vim: ts=2:sw=2:expandtab:fenc=utf8:ff=unix:tw=120
