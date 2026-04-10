/**
 * ======================================================================
 * LICENSE: This file is subject to the terms and conditions defined in *
 * file 'license.txt', which is part of this source code package.       *
 * ======================================================================
 */

(function ($) {

    /**
     * 
     * @returns {undefined}
     */
    function initialize() {
        $('input[data-toggle="toggle"]').bootstrapToggle({
            size: 'normal',
            width: '100%'
        }).bind('change', function () {
            $.ajax(aamLocal.ajaxurl, {
                type: 'POST',
                dataType: 'json',
                async: false,
                data: {
                    action: 'aam',
                    sub_action: 'Utility.save',
                    _ajax_nonce: aamLocal.nonce,
                    param: $(this).attr('name'),
                    value: $(this).prop('checked')
                },
                error: function () {
                    aam.notification('danger', aam.__('Application Error'));
                }
            });
        });

        $('#clear-settings').bind('click', function () {
            $.ajax(aamLocal.ajaxurl, {
                type: 'POST',
                dataType: 'json',
                async: false,
                data: {
                    action: 'aam',
                    sub_action: 'Utility.clear',
                    _ajax_nonce: aamLocal.nonce
                },
                success: function (response) {
                    if (response.status === 'success') {
                        location.reload();
                    }
                },
                error: function () {
                    aam.notification('danger', aam.__('Application Error'));
                }
            });
        });

        //extra UI functionality related to managing capabilities
        $('#update-capability-btn').bind('click', function (event) {
            event.preventDefault();
            
            var btn = this;
            var cap = $.trim($('#capability-id').val());
            
            if (cap) {
                $.ajax(aamLocal.ajaxurl, {
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'aam',
                        sub_action: 'Utility.updateCapability',
                        _ajax_nonce: aamLocal.nonce,
                        capability: $(this).attr('data-cap'),
                        updated: cap
                    },
                    beforeSend: function () {
                        $(btn).text(aam.__('Saving...')).attr('disabled', true);
                    },
                    success: function (response) {
                        if (response.status === 'success') {
                            $('#edit-capability-modal').modal('hide');
                            $('#capability-list').DataTable().ajax.reload();
                        } else {
                            aam.notification(
                                'danger', aam.__('Failed to update capability')
                            );
                        }
                    },
                    error: function () {
                        aam.notification('danger', aam.__('Application error'));
                    },
                    complete: function () {
                        $(btn).text(aam.__('Update Capability')).attr(
                                'disabled', false
                        );
                    }
                });
            }
        });
        
        $('#delete-capability-btn').bind('click', function (event) {
            event.preventDefault();
            
            var btn = this;
            
            $.ajax(aamLocal.ajaxurl, {
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'aam',
                    sub_action: 'Utility.deleteCapability',
                    _ajax_nonce: aamLocal.nonce,
                    subject: aam.getSubject().type,
                    subjectId: aam.getSubject().id,
                    capability: $(this).attr('data-cap')
                },
                beforeSend: function () {
                    $(btn).text(aam.__('Deleting...')).attr('disabled', true);
                },
                success: function (response) {
                    if (response.status === 'success') {
                        $('#delete-capability-modal').modal('hide');
                        $('#capability-list').DataTable().ajax.reload();
                    } else {
                        aam.notification(
                            'danger', aam.__('Failed to delete capability')
                        );
                    }
                },
                error: function () {
                    aam.notification('danger', aam.__('Application error'));
                },
                complete: function () {
                    $(btn).text(aam.__('Delete Capability')).attr(
                            'disabled', false
                    );
                }
            });
        });
    }

    /**
     * 
     * @param {type} params
     * @returns {undefined}
     */
    function decorateCapabilityRow(params) {
        if (params.action === 'edit') {
            $(params.container).append($('<i/>', {
                'class': 'aam-row-action icon-pencil text-warning'
            }).bind('click', function () {
                $('#capability-id').val(params.data[0]);
                $('#update-capability-btn').attr('data-cap', params.data[0]);
                $('#edit-capability-modal').modal('show');
            }));
        } else if (params.action === 'delete') {
            $(params.container).append($('<i/>', {
                'class': 'aam-row-action icon-trash-empty text-danger'
            }).bind('click', function () {
                var message = $('.aam-confirm-message', '#delete-capability-modal');
                $(message).html(message.data('message').replace(
                        '%s', '<b>' + params.data[0] + '</b>')
                        );
                $('#capability-id').val(params.data[0]);
                $('#delete-capability-btn').attr('data-cap', params.data[0]);
                $('#delete-capability-modal').modal('show');
            }));
        }
    }

    $(document).ready(function () {
        aam.addHook('init', initialize);
        aam.addHook('decorate-capability-row', decorateCapabilityRow);
    });

})(jQuery);