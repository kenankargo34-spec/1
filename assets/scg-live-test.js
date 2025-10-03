(function($) {
    $(document).ready(function() {
        const genBtn = $('#scg-live-generate-btn');
        const spinner = $('#scg-live-spinner');
        const resultsDiv = $('#scg-live-results');

        genBtn.on('click', function() {
            const keyword = $('#scg_live_keyword').val().trim();
            const prompt = $('#scg_live_prompt').val().trim();

            if (!keyword || !prompt) {
                alert('Lütfen anahtar kelime ve prompt girin.');
                return;
            }

            spinner.addClass('is-active');
            genBtn.prop('disabled', true);
            resultsDiv.hide();

            $.post(scg_ajax.ajax_url, {
                action: 'scg_live_generate',
                nonce: scg_ajax.nonce,
                keyword: keyword,
                prompt: prompt
            })
            .done(function(response) {
                if (response.success) {
                    const data = response.data;
                    $('#scg-result-title').text(data.title || 'Başlık bulunamadı.');
                    $('#scg-result-meta').text(data.meta || 'Meta açıklama bulunamadı.');
                    $('#scg-result-content').html(data.content || 'İçerik bulunamadı.');
                    $('#scg-result-faq').html(data.faq || 'SSS bulunamadı.');
                    resultsDiv.show();
                } else {
                    alert('Hata: ' + response.data.message);
                }
            })
            .fail(function() {
                alert('Bir ağ hatası oluştu. Lütfen tekrar deneyin.');
            })
            .always(function() {
                spinner.removeClass('is-active');
                genBtn.prop('disabled', false);
            });
        });
    });
})(jQuery);
