jQuery(document).ready(function($) {
    // Handle edit button click
    $('.scg-edit-article').on('click', function(e) {
        e.preventDefault();
        
        var postId = $(this).data('post-id');
        
        // Show loading
        $('#scg-modal-loading').show();
        $('#scg-modal-content').hide();
        
        // Open modal with animation
        $('#scg-edit-modal').fadeIn(200, function(){
            // Repaint TinyMCE when editor is inside a freshly shown modal
            if (typeof tinymce !== 'undefined' && tinymce.get('scg-post-content')) {
                try { tinymce.execCommand('mceRepaint'); } catch (e) {}
            }
        });
        
        // Ensure the 'Şimdi Çalıştır' button works even if keyword query wasn't run earlier
        jQuery(document).ready(function($){
            $('#scg-trigger-auto-run').on('click', function(e){
                e.preventDefault();
                var btn = $(this);
                var status = $('#scg-trigger-auto-run-status');
                btn.prop('disabled', true).text('Çalıştırılıyor...');
                status.text('');

                $.ajax({
                    url: scg_ajax.ajax_url,
                    type: 'POST',
                    data: { action: 'scg_trigger_auto_run', nonce: scg_ajax.nonce },
                    success: function(resp) {
                        if (!resp || !resp.success) {
                            var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Hata oluştu.';
                            status.text(msg);
                            btn.prop('disabled', false).text('Şimdi Çalıştır');
                            return;
                        }
                        var total = resp.data && resp.data.total ? parseInt(resp.data.total, 10) : 0;
                        if (total <= 0) {
                            status.text('Başlatıldı fakat işlenecek öğe yok.');
                            btn.prop('disabled', false).text('Şimdi Çalıştır');
                            return;
                        }
                        status.text('Otomatik çalışmaya başlandı — ' + total + ' öğe.');
                        $('#scg-auto-progress').attr('max', total).attr('value', 0);
                        $('#scg-auto-results').empty();

                        var done = 0;
                        function pollStep() {
                            $.ajax({
                                url: scg_ajax.ajax_url,
                                type: 'POST',
                                data: { action: 'scg_auto_step', nonce: scg_ajax.nonce },
                                success: function(step) {
                                    if (!step || !step.success) {
                                        var m = (step && step.data && step.data.message) ? step.data.message : 'Bilinmeyen hata';
                                        status.text('Hata: ' + m);
                                        btn.prop('disabled', false).text('Şimdi Çalıştır');
                                        return;
                                    }
                                    var data = step.data || {};
                                    if (data.keyword) {
                                        done = done + 1;
                                        $('#scg-auto-progress').attr('value', done);
                                        status.text('İlerleme: ' + done + '/' + total);
                                        var res = data.result || {};
                                        var line = $('<div>').addClass('scg-auto-line').text(data.keyword + ': ' + (res.success ? ('Başarılı (ID ' + res.post_id + ')') : ('Hata - ' + (res.error || ''))));
                                        $('#scg-auto-results').append(line);
                                    }
                                    if (data.done) {
                                        status.text('Tamamlandı: ' + total + '/' + total);
                                        btn.prop('disabled', false).text('Şimdi Çalıştır');
                                        setTimeout(function(){ location.reload(); }, 900);
                                        return;
                                    }
                                    setTimeout(pollStep, 700);
                                },
                                error: function(xhr) {
                                    status.text('Adım isteği başarısız: ' + (xhr.statusText || xhr.status));
                                    btn.prop('disabled', false).text('Şimdi Çalıştır');
                                }
                            });
                        }

                        setTimeout(pollStep, 300);
                    },
                    error: function(xhr) {
                        status.text('Sunucu hatası: ' + xhr.status);
                        btn.prop('disabled', false).text('Şimdi Çalıştır');
                    }
                });
            });
        });

        // Fetch article data via AJAX
        $.ajax({
            url: scg_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'scg_get_article_data',
                post_id: postId,
                nonce: scg_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Populate form fields
                    $('#scg-post-id').val(response.data.post_id);
                    $('#scg-post-title').val(response.data.post_title);
                    // Set content into TinyMCE (and fallback to textarea) with retry if editor not ready yet
                    (function setEditorContentWithRetry(html){
                        html = html || '';
                        var applied = false;
                        function apply(){
                            var ed = (typeof tinymce !== 'undefined') ? tinymce.get('scg-post-content') : null;
                            if (ed && ed.setContent) {
                                // Ensure visual tab is active
                                try { if (typeof switchEditors !== 'undefined' && switchEditors.go) { switchEditors.go('scg-post-content', 'tmce'); } } catch(_e) {}
                                ed.setContent(html);
                                ed.undoManager && ed.undoManager.clear();
                                ed.isNotDirty = 1;
                                try { tinymce.execCommand('mceRepaint'); } catch(e) {}
                                applied = true;
                                return true;
                            }
                            return false;
                        }
                        if (!apply()) {
                            // Fallback set textarea now
                            var $ta = $('#scg-post-content');
                            if ($ta.length && $ta.is('textarea')) { $ta.val(html); }
                            // Retry a few times to set TinyMCE when ready
                            var tries = 10;
                            var iv = setInterval(function(){
                                if (apply() || --tries <= 0) { clearInterval(iv); }
                            }, 100);
                        }
                    })(response.data.post_content);
                    
                    // Populate Rank Math fields
                    $('#faq_schema_data').val(response.data.faq_schema_data || '');
                    $('#rank_math_additional_keywords').val(response.data.rank_math_additional_keywords || '');
                    $('#rank_math_analytic_data').val(response.data.rank_math_analytic_data || '');
                    $('#rank_math_breadcrumb_title').val(response.data.rank_math_breadcrumb_title || '');
                    $('#rank_math_content_score').val(response.data.rank_math_content_score || '');
                    $('#rank_math_facebook_description').val(response.data.rank_math_facebook_description || '');
                    $('#rank_math_facebook_title').val(response.data.rank_math_facebook_title || '');
                    $('#rank_math_focus_keyword').val(response.data.rank_math_focus_keyword || '');
                    $('#rank_math_rich_snippet').val(response.data.rank_math_rich_snippet || '');
                    $('#rank_math_seo_score').val(response.data.rank_math_seo_score || '');
                    $('#rank_math_snippet_article_author').val(response.data.rank_math_snippet_article_author || '');
                    $('#rank_math_snippet_article_author_type').val(response.data.rank_math_snippet_article_author_type || '');
                    $('#rank_math_snippet_article_modified_date').val(response.data.rank_math_snippet_article_modified_date || '');
                    $('#rank_math_snippet_article_published_date').val(response.data.rank_math_snippet_article_published_date || '');
                    $('#rank_math_snippet_article_type').val(response.data.rank_math_snippet_article_type || '');
                    $('#rank_math_snippet_desc').val(response.data.rank_math_snippet_desc || '');
                    $('#rank_math_snippet_name').val(response.data.rank_math_snippet_name || '');
                    $('#rank_math_title').val(response.data.rank_math_title || '');
                    $('#rank_math_twitter_description').val(response.data.rank_math_twitter_description || '');
                    $('#rank_math_twitter_title').val(response.data.rank_math_twitter_title || '');

                    // Initialize FAQ Editor
                    initFaqEditor(response.data.faq_schema_data);
                    
                    // Show content
                    $('#scg-modal-loading').hide();
                    $('#scg-modal-content').show();
                    
                    // Repaint/focus editor after showing to ensure proper sizing
                    if (typeof tinymce !== 'undefined' && tinymce.get('scg-post-content')) {
                        try { tinymce.execCommand('mceRepaint'); } catch (e) {}
                        tinymce.get('scg-post-content').focus();
                    } else {
                        // Fallback: focus title
                        $('#scg-post-title').focus();
                    }
                } else {
                    alert('Error loading article data: ' + response.data.message);
                    $('#scg-edit-modal').fadeOut(200);
                }
            },
            error: function() {
                alert('Error loading article data. Please try again.');
                $('#scg-edit-modal').fadeOut(200);
            }
        });
    });

    // ----- Keyword Lists Management -----
    function scgFetchKeywordLists() {
        $.ajax({
            url: scg_ajax.ajax_url,
            type: 'POST',
            data: { action: 'scg_get_keyword_lists', nonce: scg_ajax.nonce },
            success: function(resp) {
                if (resp && resp.success) {
                    scgRenderKeywordLists(resp.data.lists || []);
                }
            }
        });
    }

    var scgListsById = {};
    var scgListRuns = {}; // { [id]: { running: bool, stop: bool, index: number, total: number } }

    function scgRenderKeywordLists(lists) {
        var table = $('#scg-keyword-lists-table');
        var countEl = $('#scg-keyword-lists-count');
        if (countEl.length) { countEl.text(lists ? lists.length : 0); }

        if (table.length) {
            var tbody = table.find('tbody');
            if (!lists || !lists.length) {
                tbody.html('<tr><td colspan="5"><em>Henüz liste yok.</em></td></tr>');
                return;
            }
            var rows = '';
            scgListsById = {};
            lists.forEach(function(item) {
                var count = (item.keywords && item.keywords.length) ? item.keywords.length : 0;
                var used = (typeof item.used_count === 'number') ? item.used_count : 0;
                scgListsById[item.id] = item;
                rows += '\
                    <tr data-id="' + (item.id || '') + '">\
                        <td><strong>' + escapeHtml(item.name || '') + '</strong></td>\
                        <td>' + count + '</td>\
                        <td>' + used + '</td>\
                        <td>\
                            <button type="button" class="button button-small scg-list-start">Başlat</button>\
                            <button type="button" class="button button-small scg-list-stop" disabled>Durdur</button>\
                            <button type="button" class="button button-small scg-list-edit">Düzenle</button>\
                            <button type="button" class="button button-small scg-list-delete">Sil</button>\
                        </td>\
                        <td style="width:220px; text-align:right;">\
                            <div class="scg-mini-progress" style="display:inline-block; width:160px; height:10px; background:#eee; border-radius:6px; overflow:hidden; vertical-align:middle;">\
                                <div class="scg-mini-progress-fill" style="width:0%; height:100%; background:#2271b1;"></div>\
                            </div>\
                            <span class="scg-mini-progress-label" style="margin-left:8px; font-size:11px; color:#555;">0/0</span>\
                        </td>\
                    </tr>';
            });
            tbody.html(rows);
            // Calculate totals for Kelime Sayısı and Kullanılan columns
            try {
                var totalWords = 0;
                var totalUsed = 0;
                lists.forEach(function(item){
                    var c = (item.keywords && item.keywords.length) ? item.keywords.length : 0;
                    var u = (typeof item.used_count === 'number') ? item.used_count : 0;
                    totalWords += Number(c);
                    totalUsed += Number(u);
                });
                // Append totals row
                tbody.append('<tr class="scg-totals-row"><td><strong>Toplam</strong></td><td><strong>' + totalWords + '</strong></td><td><strong>' + totalUsed + '</strong></td><td colspan="2"></td></tr>');
            } catch(e) { console.error('scg: error computing totals', e); }
            return;
        }

        // Fallback: render card list into #scg-keyword-lists if present
        var container = $('#scg-keyword-lists');
        if (!container.length) return;
        if (!lists || !lists.length) {
            container.html('<p class="description">Henüz liste yok. Anahtar kelime sorguladığınızda otomatik oluşturulur.</p>');
            return;
        }
        var html = '<div class="scg-lists">';
        lists.forEach(function(item) {
            var count = (item.keywords && item.keywords.length) ? item.keywords.length : 0;
            html += '\
                <div class="scg-list-card" data-id="' + (item.id || '') + '">\
                    <div class="scg-list-head">\
                        <strong>' + escapeHtml(item.name || '') + '</strong>\
                        <span class="scg-list-meta">' + count + ' kelime · ' + (item.updated_at || '') + '</span>\
                    </div>\
                    <div class="scg-list-actions">\
                        <button type="button" class="button scg-list-edit">Düzenle</button>\
                        <button type="button" class="button-link-delete scg-list-delete">Sil</button>\
                    </div>\
                </div>';
        });
        html += '</div>';
        container.html(html);
    }

    $(document).on('click', '.scg-list-delete', function() {
        var row = $(this).closest('[data-id]'); // supports table row or card
        var id = row.data('id');
        if (scgListRuns[id] && scgListRuns[id].running) { showNotification('Devam eden işlem varken silinemez.', 'error'); return; }
        if (!id) return;
        if (!confirm('Liste silinsin mi?')) return;
        $.ajax({
            url: scg_ajax.ajax_url,
            type: 'POST',
            data: { action: 'scg_delete_keyword_list', nonce: scg_ajax.nonce, id: id },
            success: function(resp) {
                if (resp && resp.success) {
                    showNotification('Liste silindi.', 'success');
                    scgFetchKeywordLists();
                } else {
                    showNotification('Liste silinemedi.', 'error');
                }
            },
            error: function() { showNotification('Liste silinemedi.', 'error'); }
        });
    });

    $(document).on('click', '.scg-list-edit', function() {
        var row = $(this).closest('[data-id]'); // supports table row or card
        var id = row.data('id');
        if (scgListRuns[id] && scgListRuns[id].running) { showNotification('Devam eden işlem varken düzenlenemez.', 'error'); return; }
        if (!id) return;
        // Open inline modal editor instead of navigating
        var list = scgListsById[id];
        if (!list) { showNotification('Liste bulunamadı.', 'error'); return; }

        // Ensure modal exists
        var $modal = $('#scg-list-edit-modal');
        if (!$modal.length) {
            var modalHtml = `
            <div id="scg-list-edit-modal" class="scg-inline-modal" style="position:fixed; inset:0; z-index:100000; display:none;">
                <div class="scg-inline-overlay" style="position:absolute; inset:0; background:rgba(0,0,0,.45);"></div>
                <div class="scg-inline-dialog" style="position:relative; width:90%; max-width:840px; margin:60px auto; background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,.3); overflow:hidden;">
                    <div class="scg-inline-header" style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e5e7eb; background:#f8fafc;">
                        <h2 style="margin:0; font-size:16px;">Anahtar Kelime Listesini Düzenle</h2>
                        <button type="button" class="button scg-inline-close">Kapat</button>
                    </div>
                    <form id="scg-inline-edit-keyword-list-form" style="padding:16px 20px;">
                        <input type="hidden" id="scg-inline-list-id" name="id" value="">
                        <div class="scg-form-group">
                            <label for="scg-inline-list-name">Liste Adı</label>
                            <input type="text" id="scg-inline-list-name" name="name" class="regular-text" style="width:100%">
                        </div>
                        <div class="scg-form-group">
                            <label for="scg-inline-list-keywords">Anahtar Kelimeler (her satıra bir)</label>
                            <textarea id="scg-inline-list-keywords" name="keywords" rows="12" style="width:100%; font-family:monospace;"></textarea>
                        </div>
                        <div class="scg-inline-footer" style="display:flex; justify-content:flex-end; gap:8px; padding-top:8px;">
                            <button type="button" class="button scg-inline-close">İptal</button>
                            <button type="submit" class="button button-primary">Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>`;
            $('body').append(modalHtml);
            $modal = $('#scg-list-edit-modal');

            // Close handlers
            $modal.on('click', '.scg-inline-close, .scg-inline-overlay', function(){
                $modal.fadeOut(150);
            });
            $(document).on('keyup.scgInlineModal', function(e){ if (e.key === 'Escape') { $modal.fadeOut(150); }});

            // Submit handler (delegated to the modal form)
            $modal.on('submit', '#scg-inline-edit-keyword-list-form', function(e){
                e.preventDefault();
                var $form = $(this);
                var idVal = $('#scg-inline-list-id').val();
                var nameVal = ($('#scg-inline-list-name').val() || '').trim();
                var kwRaw = $('#scg-inline-list-keywords').val() || '';
                var kwNorm = scgNormalizeKeywords(kwRaw);
                if (!nameVal) { showNotification('Lütfen liste adını girin.', 'error'); return; }
                var $btn = $form.find('button[type="submit"]');
                var old = $btn.text();
                $btn.prop('disabled', true).text('Kaydediliyor...');
                $.ajax({
                    url: scg_ajax.ajax_url,
                    type: 'POST',
                    data: { action: 'scg_update_keyword_list', nonce: scg_ajax.nonce, id: idVal, name: nameVal, keywords: kwNorm },
                    success: function(resp){
                        if (resp && resp.success) {
                            showNotification('Liste kaydedildi.', 'success');
                            $modal.fadeOut(150);
                            scgFetchKeywordLists();
                        } else if (resp && resp.data && resp.data.message) {
                            showNotification('Hata: ' + resp.data.message, 'error');
                        } else {
                            showNotification('Kaydetme başarısız.', 'error');
                        }
                    },
                    error: function(){ showNotification('Sunucu hatası.', 'error'); },
                    complete: function(){ $btn.prop('disabled', false).text(old); }
                });
            });
        }

        // Prefill
        $('#scg-inline-list-id').val(list.id || '');
        $('#scg-inline-list-name').val(list.name || '');
        var kw = Array.isArray(list.keywords) ? list.keywords.join('\n') : '';
        $('#scg-inline-list-keywords').val(kw);

        // Show
        $modal.fadeIn(150);
    });

    // Initialize lists on Keywords Settings page
    $(function(){
        if ($('#scg-keyword-lists').length || $('#scg-keyword-lists-table').length) {
            scgFetchKeywordLists();
        }
    });

    // Auto Publish controls (if present on page)
    $(function(){
        if ($('#scg-auto-start').length) {
            $('#scg-auto-start').on('click', function(e){
                e.preventDefault();
                $('#scg-trigger-auto-run').trigger('click');
            });
        }

        if ($('#scg-auto-clear').length) {
            $('#scg-auto-clear').on('click', function(e){
                e.preventDefault();
                if (!confirm('Kuyruk ve son çalışma silinsin mi?')) return;
                var btn = $(this); btn.prop('disabled', true).text('Temizleniyor...');
                $.post(scg_ajax.ajax_url, { action: 'scg_auto_clear', nonce: scg_ajax.nonce }, function(resp){
                    if (resp && resp.success) {
                        $('#scg-auto-results').empty();
                        $('#scg-auto-status').text('Kuyruk temizlendi.');
                        $('#scg-auto-progress').attr('max',0).attr('value',0);
                    } else {
                        alert('Temizleme başarısız.');
                    }
                }).fail(function(){ alert('Sunucu hatası'); }).always(function(){ btn.prop('disabled', false).text('Sil'); });
            });
        }
        if ($('#scg-auto-stop').length) {
            $('#scg-auto-stop').on('click', function(e){
                e.preventDefault();
                var btn = $(this); btn.prop('disabled', true).text('Durduruluyor...');
                $.post(scg_ajax.ajax_url, { action: 'scg_auto_stop', nonce: scg_ajax.nonce }, function(resp){
                    if (resp && resp.success) {
                        $('#scg-auto-status').text('Durdurma isteği gönderildi. Mevcut adım tamamlanıp işlem duracaktır.');
                    } else {
                        alert('Durdurma isteği başarısız.');
                    }
                }).fail(function(){ alert('Sunucu hatası'); }).always(function(){ btn.prop('disabled', false).text('Durdur'); });
            });
        }
    });

    // Initialize DataTable for generated articles table if present
    $(function(){
        var $tbl = $('#scg-generated-articles-table');
        var $autoTbl = $('#scg-auto-published-table');
        if ($tbl.length && $.fn.DataTable) {
            // Add page length selector above the table
            var lengthSelector = '<label style="margin-right:10px;">Sayfa başına: <select id="scg-articles-per-page" class="scg-select"><option>10</option><option>20</option><option>50</option><option>100</option></select></label>';
            $tbl.before('<div class="scg-dt-controls" style="margin-bottom:8px;">' + lengthSelector + '</div>');

            var dt = $tbl.DataTable({
                paging: true,
                pageLength: 20,
                lengthChange: false,
                ordering: true,
                order: [[3, 'desc']], // order by date column (4th column zero-indexed)
                columnDefs: [ { orderable: false, targets: [0,4] } ]
            });

            // Wire up custom per-page selector
            $('#scg-articles-per-page').on('change', function(){
                var val = parseInt($(this).val(), 10) || 20;
                dt.page.len(val).draw();
            }).val(20);
        }

        // Do not initialize DataTable for the server-paginated auto-published table.
        // Server-side pagination is controlled via the PHP `posts_per_page` and paginate_links.
    });

    // ---- Per-list generation logic ----
    function scgUpdateRowProgress(row, idx, total) {
        var percent = total > 0 ? Math.round((idx / total) * 100) : 0;
        row.find('.scg-mini-progress-fill').css('width', percent + '%');
        row.find('.scg-mini-progress-label').text((idx) + '/' + (total));
    }

    function scgProcessListNext(id) {
        var run = scgListRuns[id];
        if (!run || !run.running) return;
        var row = $('[data-id="' + id + '"]');
        var list = scgListsById[id];
        if (!list || !list.keywords || !list.keywords.length) {
            run.running = false;
            row.find('.scg-list-start').prop('disabled', false);
            row.find('.scg-list-stop').prop('disabled', true);
            return;
        }
        if (run.index >= run.total || run.stop) {
            run.running = false;
            scgUpdateRowProgress(row, run.index, run.total);
            row.find('.scg-list-start').prop('disabled', false);
            row.find('.scg-list-stop').prop('disabled', true);
            showNotification('İşlem tamamlandı: ' + (list.name || ''), 'success');
            return;
        }
        var keyword = list.keywords[run.index];

        // Call AJAX to generate article
        $.ajax({
            url: scg_ajax.ajax_url,
            type: 'POST',
            data: { action: 'scg_generate_article', nonce: scg_ajax.nonce, keyword: keyword },
            success: function(resp) {
                // advance regardless of success or error
                run.index += 1;
                scgUpdateRowProgress(row, run.index, run.total);
            },
            error: function() {
                run.index += 1;
                scgUpdateRowProgress(row, run.index, run.total);
            },
            complete: function() {
                // next iteration if still running and not stopped
                setTimeout(function(){ scgProcessListNext(id); }, 150);
            }
        });
    }

    $(document).on('click', '.scg-list-start', function() {
        var row = $(this).closest('tr[data-id]');
        var id = row.data('id');
        var list = scgListsById[id];
        if (!list) { showNotification('Liste bulunamadı.', 'error'); return; }
        var total = (list.keywords && list.keywords.length) ? list.keywords.length : 0;
        if (total === 0) { showNotification('Listede kelime yok.', 'error'); return; }

        scgListRuns[id] = { running: true, stop: false, index: 0, total: total };
        row.find('.scg-list-start').prop('disabled', true);
        row.find('.scg-list-stop').prop('disabled', false);
        scgUpdateRowProgress(row, 0, total);
        showNotification('Başlatıldı: ' + (list.name || ''), 'success');
        scgProcessListNext(id);
    });

    $(document).on('click', '.scg-list-stop', function() {
        var row = $(this).closest('tr[data-id]');
        var id = row.data('id');
        if (!scgListRuns[id] || !scgListRuns[id].running) return;
        scgListRuns[id].stop = true;
        showNotification('Durdurma isteği alındı.', 'success');
    });
    
    // Handle modal close
    $('.scg-modal-close, #scg-edit-modal .scg-modal-overlay').on('click', function() {
        $('#scg-edit-modal').fadeOut(200);
    });
    
    // Close modal with ESC key
    $(document).on('keyup', function(e) {
        if (e.key === "Escape") {
            $('#scg-edit-modal').fadeOut(200);
        }
    });
    
    // Handle form submission
    $('#scg-edit-form').on('submit', function(e) {
        e.preventDefault();
        
        // Sync TinyMCE content back to textarea before serialize
        try { if (typeof tinyMCE !== 'undefined' && tinyMCE.triggerSave) { tinyMCE.triggerSave(); } } catch (err) {}

        // Show loading
        $('#scg-modal-content').hide();
        $('#scg-modal-loading').show();
        
        // Change save button text
        var saveButton = $('.scg-modal-footer .button-primary');
        var originalText = saveButton.text();
        saveButton.text('Saving...').prop('disabled', true);
        
        // Submit form via AJAX
        $.ajax({
            url: scg_ajax.ajax_url,
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response.success) {
                    // Show success message
                    $('#scg-modal-loading p').text('Article updated successfully!').css('color', '#008a20');
                    
                    // Close modal after delay
                    setTimeout(function() {
                        $('#scg-edit-modal').fadeOut(200);
                        location.reload();
                    }, 1000);
                } else {
                    // Show error message
                    $('#scg-modal-loading p').text('Error: ' + response.data.message).css('color', '#d63638');
                    
                    // Restore form after delay
                    setTimeout(function() {
                        $('#scg-modal-loading').hide();
                        $('#scg-modal-content').show();
                        saveButton.text(originalText).prop('disabled', false);
                    }, 2000);
                }
            },
            error: function() {
                // Show error message
                $('#scg-modal-loading p').text('Error updating article. Please try again.').css('color', '#d63638');
                
                // Restore form after delay
                setTimeout(function() {
                    $('#scg-modal-loading').hide();
                    $('#scg-modal-content').show();
                    saveButton.text(originalText).prop('disabled', false);
                }, 2000);
            }
        });
    });
    
    // Add focus styling to form fields
    $('.scg-form-group input, .scg-form-group textarea').on('focus', function() {
        $(this).addClass('focused');
    }).on('blur', function() {
        $(this).removeClass('focused');
    });

    // --- Dedicated Edit Page: handle form submit ---
    $(document).on('submit', '#scg-edit-keyword-list-form', function(e) {
        e.preventDefault();
        var form = $(this);
        var id = $('#scg-edit-list-id').val();
        var name = ($('#scg-edit-list-name').val() || '').trim();
        var keywordsRaw = $('#scg-edit-list-keywords').val() || '';
        var keywords = scgNormalizeKeywords(keywordsRaw);
        if (!name) { showNotification('Lütfen liste adını girin.', 'error'); return; }

        var btn = form.find('button[type="submit"]');
        var originalHtml = btn.html();
        btn.prop('disabled', true).text('Kaydediliyor...');

        $.ajax({
            url: scg_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'scg_update_keyword_list',
                nonce: scg_ajax.nonce,
                id: id,
                name: name,
                keywords: keywords
            },
            success: function(resp) {
                if (resp && resp.success) {
                    showNotification('Liste kaydedildi.', 'success');
                    setTimeout(function(){ window.location.href = 'admin.php?page=scg-keywords-settings'; }, 800);
                } else if (resp && resp.data && resp.data.message) {
                    showNotification('Hata: ' + resp.data.message, 'error');
                } else {
                    showNotification('Kaydetme başarısız.', 'error');
                }
            },
            error: function() {
                showNotification('Sunucu hatası.', 'error');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalHtml);
            }
        });
    });
    
    // FAQ Accordion Functionality - Implemented like FAQ Schema Fixer
    function initFAQAccordion() {
        // Handle clicks on FAQ questions
        $(document).off('click', '.faq-question, .faq-item h3, .faq-item h4').on('click', '.faq-question, .faq-item h3, .faq-item h4', function(e) {
            e.preventDefault();
            
            // Get the FAQ item container
            var item = $(this).closest('.faq-item');
            
            // Check if this item is currently active
            var isActive = item.hasClass('active');
            
            // Close all FAQ items (accordion behavior)
            $('.faq-item').removeClass('active');
            $('.faq-answer').removeClass('active').css({
                'max-height': '0',
                'padding': '0 25px'
            });
            
            // If the clicked item wasn't active, open it
            if (!isActive) {
                item.addClass('active');
                item.find('.faq-answer').addClass('active').css({
                    'max-height': '500px',
                    'padding': '20px 25px'
                });
            }
        });
    }
    
    // Initialize FAQ accordion when document is ready
    $(document).ready(function() {
        initFAQAccordion();
    });
    
    // Add click handler for view article buttons
    $(document).on('click', '.scg-view-article', function(e) {
        e.preventDefault();
        var url = $(this).data('url');
        if (url) {
            window.open(url, '_blank');
        }
    });

    // --- Generated Articles: Select-all checkbox behavior ---
    // Toggle all row checkboxes when header select-all changes
    $(document).on('change', '#scg-select-all', function() {
        var $headerCb = $(this);
        var $table = $headerCb.closest('table');
        var checked = $headerCb.is(':checked');
        $headerCb.prop('indeterminate', false);
        $table.find('tbody input[type="checkbox"][name="posts[]"]').prop('checked', checked);
    });

    // Keep select-all in sync when any row checkbox changes
    $(document).on('change', 'table .scg-table-checkbox input[type="checkbox"][name="posts[]"]', function() {
        var $table = $(this).closest('table');
        var $rows = $table.find('tbody input[type="checkbox"][name="posts[]"]');
        var total = $rows.length;
        var checked = $rows.filter(':checked').length;
        var $headerCb = $table.find('#scg-select-all');
        if (!$headerCb.length) return;
        if (checked === 0) {
            $headerCb.prop({checked:false, indeterminate:false});
        } else if (checked === total) {
            $headerCb.prop({checked:true, indeterminate:false});
        } else {
            $headerCb.prop({checked:false, indeterminate:true});
        }
    });

    // --- Reusable Iframe Modal for Quick Actions ---
    $(document).on('click', '.scg-open-modal', function(e) {
        // Only intercept left-click without modifier keys
        if (e.which !== 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        var $a = $(this);
        var href = $a.attr('href');
        if (!href) return;
        var title = $a.data('title') || $a.text() || 'Aksiyon';

        var $modal = $('#scg-iframe-modal');
        if (!$modal.length) {
            var html = `
            <div id="scg-iframe-modal" style="position:fixed; inset:0; z-index:100001; display:none;">
                <div class="scg-inline-overlay" style="position:absolute; inset:0; background:rgba(0,0,0,.5);"></div>
                <div class="scg-inline-dialog" style="position:relative; width:92%; max-width:1100px; height:84vh; margin:5vh auto; background:#fff; border-radius:12px; box-shadow:0 24px 70px rgba(0,0,0,.35); overflow:hidden; display:flex; flex-direction:column;">
                    <div class="scg-inline-header" style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #e5e7eb; background:#f8fafc;">
                        <h2 id="scg-iframe-title" style="margin:0; font-size:16px;">Modal</h2>
                        <button type="button" class="button scg-iframe-close">Kapat</button>
                    </div>
                    <div class="scg-iframe-wrap" style="position:relative; flex:1;">
                        <div class="scg-iframe-loading" style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:#ffffff;">
                            <span class="dashicons dashicons-update scg-spin" style="font-size:22px;"></span> <span style="margin-left:8px;">Yükleniyor...</span>
                        </div>
                        <iframe id="scg-iframe" src="about:blank" style="width:100%; height:100%; border:0;" loading="lazy"></iframe>
                    </div>
                </div>
            </div>`;
            $('body').append(html);
            $modal = $('#scg-iframe-modal');

            $modal.on('click', '.scg-iframe-close, .scg-inline-overlay', function(){
                $modal.fadeOut(150);
                // Clear src to stop any running tasks
                $('#scg-iframe').attr('src', 'about:blank');
            });
        }

        $('#scg-iframe-title').text(title);
        $('.scg-iframe-loading', $modal).show();
        var $iframe = $('#scg-iframe');
        $iframe.off('load').on('load', function(){
            $('.scg-iframe-loading', $modal).fadeOut(150);
        });
        // Append a flag to let inner page know it's inside modal (optional)
        var url = new URL(href, window.location.origin);
        url.searchParams.set('scg_modal', '1');
        $iframe.attr('src', url.toString());
        $modal.fadeIn(150);
    });

    // Handle single article generation via AJAX on Generated Articles page
    $(document).on('submit', '#generate-article-form', function(e) {
        e.preventDefault();
        var form = $(this);
        var keywordInput = form.find('#generate_keyword');
        var btn = form.find('button[type="submit"]');
        var keyword = keywordInput.val().trim();

        if (!keyword) {
            showNotification('Lütfen anahtar kelime girin.', 'error');
            keywordInput.focus();
            return;
        }

        // Switch button to progress style
        var originalHtml = btn.html();
        btn.data('scg-original-html', originalHtml);
        btn.prop('disabled', true).addClass('scg-progress');
        btn.html('<span class="dashicons dashicons-update scg-spin"></span> <span class="scg-progress-label">Makale oluşturuluyor...</span><span class="scg-btn-progress"></span>');

        $.ajax({
            url: scg_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'scg_generate_article',
                nonce: scg_ajax.nonce,
                keyword: keyword
            },
            success: function(response) {
                if (response && response.success) {
                    var d = response.data || {};
                    var msg = d.message || 'Makale başarıyla oluşturuldu!';
                    var links = '';
                    if (d.view_url) {
                        links += ' <a href="' + d.view_url + '" target="_blank">Görüntüle</a>';
                    }
                    if (d.edit_url) {
                        links += ' | <a href="' + d.edit_url + '" target="_blank">Düzenle</a>';
                    }
                    showNotification(msg + links, 'success');
                    // Optionally clear input
                    keywordInput.val('');
                } else {
                    var err = (response && response.data && response.data.error && response.data.error.message) ? response.data.error.message : 'Bilinmeyen hata.';
                    var base = (response && response.data && response.data.message) ? response.data.message : 'Makale oluşturulamadı.';
                    showNotification(base + ' Detay: ' + err, 'error');
                }
            },
            error: function(xhr) {
                showNotification('Sunucu hatası: ' + (xhr.status || '') + ' ' + (xhr.statusText || ''), 'error');
            },
            complete: function() {
                // Restore original button
                btn.prop('disabled', false).removeClass('scg-progress');
                var html = btn.data('scg-original-html');
                if (html) { btn.html(html); }
            }
        });
    });

    // Add click handler for keyword query button
    $(document).on('click', '#scg-query-keywords', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var loadingIndicator = $('#scg-keyword-loading');
        var keywordsTextarea = $('#scg_keywords');
        var queryInput = $('#scg-query-input');
        var queryKeyword = queryInput.val().trim();
        
        // Validate input
        if (!queryKeyword) {
            showNotification('Lütfen sorgulanacak ana kelimeyi girin.', 'error');
            queryInput.focus();
            return;
        }
        
        // Show loading state
        button.prop('disabled', true);
        loadingIndicator.show();
        
        // Make AJAX request
        $.ajax({
            url: scg_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'scg_query_keywords',
                query_keyword: queryKeyword,
                nonce: scg_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Get current keywords
                    var currentKeywords = keywordsTextarea.val().trim();
                    var newKeywords = scgNormalizeKeywords(response.data.keywords).trim();
                    
                    // Append new keywords to existing ones
                    if (currentKeywords) {
                        keywordsTextarea.val(currentKeywords + '\n' + newKeywords);
                    } else {
                        keywordsTextarea.val(newKeywords);
                    }
                    
                    // Show success message
                    showNotification('"' + queryKeyword + '" için 20 adet uzun kuyruklu kelime başarıyla eklendi!', 'success');

                    // Auto-create or merge a keyword list named after the queried keyword
                    if ($('#scg-keyword-lists').length || $('#scg-keyword-lists-table').length) {
                        $.ajax({
                            url: scg_ajax.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'scg_save_keyword_list',
                                nonce: scg_ajax.nonce,
                                name: queryKeyword,
                                keywords: newKeywords
                            },
                            success: function(resp) {
                                if (resp && resp.success) {
                                    var msg = (resp.data && resp.data.message) ? resp.data.message : ('Kelime listesi oluşturuldu: ' + queryKeyword);
                                    showNotification(msg, 'success');
                                    scgFetchKeywordLists();
                                } else if (resp && resp.data && resp.data.message) {
                                    showNotification('Liste oluşturma hatası: ' + resp.data.message, 'error');
                                }
                            },
                            error: function() {
                                showNotification('Liste oluşturulamadı.', 'error');
                            }
                        });

                        // Handler for triggering automatic run from settings UI (queued + polling)
                        jQuery(document).ready(function($){
                            $('#scg-trigger-auto-run').on('click', function(e){
                                e.preventDefault();
                                var btn = $(this);
                                var status = $('#scg-trigger-auto-run-status');
                                btn.prop('disabled', true).text('Çalıştırılıyor...');
                                status.text('');
                                $.ajax({
                                    url: scg_ajax.ajax_url,
                                    type: 'POST',
                                    data: { action: 'scg_trigger_auto_run', nonce: scg_ajax.nonce },
                                    success: function(resp){
                                        if (resp && resp.success) {
                                            var total = resp.data && resp.data.total ? parseInt(resp.data.total, 10) : 0;
                                            if (total <= 0) {
                                                status.text('Başlatıldı fakat işlenecek öğe yok.');
                                                btn.prop('disabled', false).text('Şimdi Çalıştır');
                                                return;
                                            }
                                            status.text('Otomatik çalışmaya başlandı — ' + total + ' öğe.');
                                            startScgPolling(total, btn, status);
                                        } else {
                                            var msg = 'Hata oluştu.';
                                            try {
                                                if (resp && resp.data && resp.data.message) { msg = resp.data.message; }
                                                else if (resp && resp.data) { msg = JSON.stringify(resp.data); }
                                            } catch (e) {}
                                            status.text(msg);
                                            btn.prop('disabled', false).text('Şimdi Çalıştır');
                                        }
                                    },
                                    error: function(xhr){
                                        status.text('Sunucu hatası: ' + xhr.status);
                                        btn.prop('disabled', false).text('Şimdi Çalıştır');
                                    }
                                });
                            });

                            var scgPolling = { running: false, total: 0, done: 0 };
                            function startScgPolling(total, btn, status) {
                                if (scgPolling.running) return;
                                scgPolling.running = true;
                                scgPolling.total = total;
                                scgPolling.done = 0;
                                $('#scg-auto-progress').attr('max', total).attr('value', 0);
                                $('#scg-auto-results').empty();
                                status.text('İlerleme: 0/' + total);

                                function pollStep() {
                                    $.ajax({
                                        url: scg_ajax.ajax_url,
                                        type: 'POST',
                                        data: { action: 'scg_auto_step', nonce: scg_ajax.nonce },
                                        success: function(resp) {
                                            if (!resp || !resp.success) {
                                                var m = (resp && resp.data && resp.data.message) ? resp.data.message : 'Bilinmeyen hata';
                                                status.text('Hata: ' + m);
                                                scgPolling.running = false;
                                                btn.prop('disabled', false).text('Şimdi Çalıştır');
                                                return;
                                            }
                                            var data = resp.data || {};
                                            if (data.keyword) {
                                                scgPolling.done = scgPolling.done + 1;
                                                $('#scg-auto-progress').attr('value', scgPolling.done);
                                                status.text('İlerleme: ' + scgPolling.done + '/' + scgPolling.total);
                                                var res = data.result || {};
                                                var line = $('<div>').addClass('scg-auto-line').text(data.keyword + ': ' + (res.success ? ('Başarılı (ID ' + res.post_id + ')') : ('Hata - ' + (res.error || ''))));
                                                $('#scg-auto-results').append(line);
                                            }
                                            if (data.done) {
                                                status.text('Tamamlandı: ' + scgPolling.total + '/' + scgPolling.total);
                                                scgPolling.running = false;
                                                btn.prop('disabled', false).text('Şimdi Çalıştır');
                                                setTimeout(function(){ location.reload(); }, 900);
                                                return;
                                            }
                                            // continue polling
                                            setTimeout(pollStep, 700);
                                        },
                                        error: function(xhr) {
                                            status.text('Adım isteği başarısız: ' + xhr.statusText);
                                            scgPolling.running = false;
                                            btn.prop('disabled', false).text('Şimdi Çalıştır');
                                        }
                                    });
                                }

                                // start first poll shortly
                                setTimeout(pollStep, 300);
                            }
                        });
                    }
                    
                    // Clear input field
                    queryInput.val('');
                    
                    // Scroll to textarea
                    $('html, body').animate({
                        scrollTop: keywordsTextarea.offset().top - 100
                    }, 500);
                    
                } else {
                    showNotification('Hata: ' + response.data.message, 'error');
                }
            },
            error: function() {
                showNotification('Kelime sorgulaması sırasında bir hata oluştu. Lütfen tekrar deneyin.', 'error');
            },
            complete: function() {
                // Hide loading state
                button.prop('disabled', false);
                loadingIndicator.hide();
            }
        });
    });
    
    // --- FAQ Editor Functions ---

    function initFaqEditor(faqSchemaData) {
        const container = $('#scg-faq-editor-container');
        container.empty(); // Clear previous items

        let faqData;
        try {
            faqData = faqSchemaData ? JSON.parse(faqSchemaData) : createEmptyFaqSchema();
            if (!faqData.mainEntity) {
                faqData.mainEntity = [];
            }
        } catch (e) {
            console.error('Error parsing FAQ JSON:', e);
            faqData = createEmptyFaqSchema();
        }

        if (faqData.mainEntity.length > 0) {
            faqData.mainEntity.forEach((item, index) => {
                container.append(createFaqItemHtml(index, item.name, item.acceptedAnswer.text));
            });
        } else {
            // Add a default empty item if none exist
            container.append(createFaqItemHtml(0, '', ''));
            updateFaqSchemaData(); // Update hidden field
        }

        // Bind events
        bindFaqEditorEvents();
    }

    function createEmptyFaqSchema() {
        return {
            '@context': 'https://schema.org',
            '@type': 'FAQPage',
            'mainEntity': []
        };
    }

    function createFaqItemHtml(index, question, answer) {
        return `
            <div class="scg-faq-item" data-index="${index}">
                <div class="scg-form-group">
                    <label for="faq-question-${index}">Soru</label>
                    <input type="text" id="faq-question-${index}" class="scg-faq-question-input" value="${escapeHtml(question)}" placeholder="Soruyu buraya girin">
                </div>
                <div class="scg-form-group">
                    <label for="faq-answer-${index}">Cevap</label>
                    <textarea id="faq-answer-${index}" class="scg-faq-answer-input" rows="3" placeholder="Cevabı buraya girin">${escapeHtml(answer)}</textarea>
                </div>
                <div class="scg-faq-actions">
                    <button type="button" class="scg-faq-delete-button">Sil</button>
                </div>
            </div>
        `;
    }

    function bindFaqEditorEvents() {
        const container = $('#scg-faq-editor-container');

        // Use event delegation for dynamically added items
        container.off('input').on('input', '.scg-faq-question-input, .scg-faq-answer-input', function() {
            updateFaqSchemaData();
        });

        container.off('click').on('click', '.scg-faq-delete-button', function() {
            $(this).closest('.scg-faq-item').remove();
            updateFaqSchemaData();
        });
    }

    $('#scg-add-faq').off('click').on('click', function() {
        const container = $('#scg-faq-editor-container');
        const newIndex = container.children('.scg-faq-item').length;
        container.append(createFaqItemHtml(newIndex, '', ''));
        updateFaqSchemaData();
    });

    function updateFaqSchemaData() {
        const faqSchema = createEmptyFaqSchema();
        $('#scg-faq-editor-container .scg-faq-item').each(function() {
            const question = $(this).find('.scg-faq-question-input').val();
            const answer = $(this).find('.scg-faq-answer-input').val();

            if (question && answer) {
                faqSchema.mainEntity.push({
                    '@type': 'Question',
                    'name': question,
                    'acceptedAnswer': {
                        '@type': 'Answer',
                        'text': answer
                    }
                });
            }
        });

        $('#faq_schema_data').val(JSON.stringify(faqSchema, null, 2));
    }

    function escapeHtml(text) {
        if (typeof text !== 'string') return '';
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Normalize keyword lines: strip leading bullets or numbers and trim
    function scgNormalizeKeywords(str) {
        if (!str) return '';
        var lines = String(str).split(/\r?\n/);
        var cleaned = [];
        var seen = {};
        lines.forEach(function(line){
            var t = (line || '').trim();
            // Remove leading bullets like *, -, •, ·, or numbering like 1., 1), 1- etc.
            t = t.replace(/^([*•·\-]+|\d+[\.)\-]*)\s*/u, '');
            if (t) {
                var key = t.toLowerCase();
                if (!seen[key]) { seen[key] = true; cleaned.push(t); }
            }
        });
        return cleaned.join('\n');
    }

    // Notification function
    function showNotification(message, type) {
        var notificationClass = type === 'success' ? 'notice-success' : 'notice-error';
        var notification = $('<div class="notice ' + notificationClass + ' is-dismissible scg-notification">\
            <p>' + message + '</p>\
            <button type="button" class="notice-dismiss"><span class="screen-reader-text">Kapat</span></button>\
        </div>');
        
        // Add to page
        $('.wrap h1').after(notification);
        
        // Auto remove after 5 seconds
        setTimeout(function() {
            notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
        
        // Add dismiss functionality
        notification.on('click', '.notice-dismiss', function() {
            notification.fadeOut(300, function() {
                $(this).remove();
            });
        });
    }

        // === Otomatik Yayınlama Progress ve Butonları ===
        var autoPublishTotal = 50; // örnek, backend'den alınabilir
        var autoPublishCurrent = 0;
        var autoPublishInterval = null;

        function updateAutoPublishProgress() {
            var percent = autoPublishTotal > 0 ? (100 * autoPublishCurrent / autoPublishTotal) : 0;
            $('#scg-auto-publish-progress-fill').css('width', percent + '%');
            $('#scg-auto-publish-progress-label').text(autoPublishCurrent + '/' + autoPublishTotal);
        }

        $('#scg-auto-publish-start').on('click', function() {
            if (autoPublishInterval) return;
            autoPublishInterval = setInterval(function() {
                if (autoPublishCurrent < autoPublishTotal) {
                    autoPublishCurrent++;
                    updateAutoPublishProgress();
                } else {
                    clearInterval(autoPublishInterval);
                    autoPublishInterval = null;
                    showNotification('Otomatik yayınlama tamamlandı!', 'success');
                }
            }, 300);
        });

        $('#scg-auto-publish-stop').on('click', function() {
            if (autoPublishInterval) {
                clearInterval(autoPublishInterval);
                autoPublishInterval = null;
                showNotification('Otomatik yayınlama durduruldu.', 'success');
            }
        });

        $('#scg-auto-publish-reset').on('click', function() {
            autoPublishCurrent = 0;
            updateAutoPublishProgress();
            showNotification('İlerleme sıfırlandı.', 'success');
        });

        // Sayfa yüklenince progress barı başlat
        updateAutoPublishProgress();
});
