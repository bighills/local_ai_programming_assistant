/**
 * AI 编程助手 - 知识库管理页面 JS
 *
 * @module     local_ai_programming_assistant/kb_manage
 * @package    local_ai_programming_assistant
 */
define(['jquery', 'core/notification'], function($, Notification) {
    return {
        init: function() {
            // 缓存 DOM 查询
            var $selectAll = $('#selectall');
            var $batchDeleteForm = $('#batchdeleteform');
            var $batchDeleteBtn = $('#batchdeletebtn');

            // 缓存 .file-select 引用并保持同步（每次 change 后刷新）
            var allCheckboxes = function() { return $('.file-select'); };
            var checkedBoxes = function() { return $('.file-select:checked'); };

            // 删除确认
            $('[data-confirm]').on('click', function(e) {
                var message = $(this).data('confirm');
                if (!confirm(message)) {
                    e.preventDefault();
                }
            });

            // 教材名称内联编辑
            function startEditing($span) {
                if ($span.find('input').length) {
                    return; // 已经在编辑中
                }
                var currentName = $span.text().trim();
                var fileid = $span.data('fileid');
                var $input = $('<input>', {
                    type: 'text',
                    value: currentName,
                    class: 'form-control form-control-sm d-inline-block',
                    style: 'width:auto;min-width:150px;'
                });
                $span.empty().append($input);
                $input.focus().select();

                // 防止 Enter 与 blur 双触发重复保存
                var done = false;

                function save() {
                    if (done) {
                        return;
                    }
                    done = true;
                    var newName = $input.val().trim();
                    if (newName === '' || newName === currentName) {
                        $span.text(currentName);
                        return;
                    }
                    $.ajax({
                        url: window.location.pathname,
                        method: 'POST',
                        data: {
                            action: 'rename',
                            fileid: fileid,
                            newname: newName,
                            sesskey: M.cfg.sesskey
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                $span.text(newName);
                            } else {
                                $span.text(currentName);
                                Notification.alert('Error', response.error || 'Rename failed');
                            }
                        },
                        error: function() {
                            $span.text(currentName);
                            Notification.alert('Error', 'Rename failed');
                        }
                    });
                }

                $input.on('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        save();
                    } else if (e.key === 'Escape') {
                        // Escape 分支同样标记 done，防止随后的 blur 再次触发 save()
                        done = true;
                        $input.off('blur');
                        $span.text(currentName);
                    }
                });

                $input.on('blur', function() {
                    save();
                });
            }

            // 双击名称编辑
            $(document).on('dblclick', '.editable-name', function() {
                startEditing($(this));
            });

            // 点击铅笔图标编辑
            $(document).on('click', '.edit-icon', function() {
                var $span = $(this).closest('td').find('.editable-name');
                if ($span.length) {
                    startEditing($span);
                }
            });

            // 全选/取消全选
            $selectAll.on('change', function() {
                var isChecked = $(this).prop('checked');
                allCheckboxes().prop('checked', isChecked);
                updateBatchDeleteButton();
            });

            // 单个 checkbox 变化时更新全选状态和按钮
            $(document).on('change', '.file-select', function() {
                var total = allCheckboxes().length;
                var checked = checkedBoxes().length;
                $selectAll.prop('checked', total === checked);
                updateBatchDeleteButton();
            });

            function updateBatchDeleteButton() {
                var checkedCount = checkedBoxes().length;
                $batchDeleteBtn.prop('disabled', checkedCount === 0);
            }

            // 批量删除
            $batchDeleteBtn.on('click', function() {
                var checked = checkedBoxes();
                if (checked.length === 0) {
                    return;
                }
                var confirmMsg = M.util.get_string('knowledgebase_confirm_batch_delete', 'local_ai_programming_assistant', checked.length);
                if (!confirm(confirmMsg)) {
                    return;
                }
                var fileids = [];
                checked.each(function() {
                    fileids.push($(this).val());
                });
                // 循环创建隐藏 input 避免重复代码
                var inputs = [
                    {name: 'action', value: 'batchdelete'},
                    {name: 'fileids', value: fileids.join(',')},
                    {name: 'sesskey', value: M.cfg.sesskey}
                ];
                inputs.forEach(function(input) {
                    $('<input>', {type: 'hidden'}).attr(input).appendTo($batchDeleteForm);
                });
                $batchDeleteForm.submit();
            });
        }
    };
});