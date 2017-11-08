(function($) {
	$(function() {

		// Update Publish metabox revisions info not to include autosave revisions
		var $browseRevisionsLink = $('.misc-pub-revisions a'),
			$revisionsCount = $('.misc-pub-revisions b').text(fprData.revisionsCount);
		if (fprData.pendingCount && parseInt(fprData.pendingCount) > 0) {
			$revisionsCount.after($('<span>', {text: ' (' + fprData.pendingCount + ' pending)'}));
		}
		$browseRevisionsLink.attr('href', fprData.urls.revisions);

		// Handle permissions metabox saving
		var $button = $('.fpr-editing-mode__button button');
		$button.click(function(event) {
			$button.attr('disabled', true);
			$('html').addClass('fpr-util-wait');
			$('.fpr-editing-mode__saved-notice').remove();
			$.ajax({
				type: 'POST',
				url: fprData.urls.ajax,
				context: this,
				data: {
					action: 'fpr-editing-mode-save',
					security: fprData.nonce,
					data: {
						postID: fprData.post.ID,
						editingMode: $('input[name="fpr-editing-mode"]:checked').val()
					}
				}
			}).done(function(response) {
				$button.attr('disabled', false);
				$('html').removeClass('fpr-util-wait');
				if (response.success) {
					$('.fpr-editing-mode__button').prepend(
						$('<span>', {
							class: 'fpr-editing-mode__saved-notice fpr-editing-mode__saved-notice--success',
							text: 'Saved'
						})
					);
				} else {
					$('.fpr-editing-mode__button').prepend(
						$('<span>', {
							class: 'fpr-editing-mode__saved-notice fpr-editing-mode__saved-notice--error',
							text: 'Error saving'
						})
					);
				}
			});
		});

		// Show spinner when saving pending changes
		$('#fpr-pending-revisions-submit').click(function(event) {
			$("#major-publishing-actions .spinner").addClass("is-active");
		});
	});
})(jQuery);
