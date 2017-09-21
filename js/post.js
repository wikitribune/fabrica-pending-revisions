(function($) {
	$(function() {

		// Disable update/publish button if post is locked and user is not editor
		if (fprData.editingMode === 'locked' && !fprData.canUserPublishPosts) {
			$('#publish').attr('disabled', true);
			$('#major-publishing-actions').show();
		}

		// Handle permissions metabox saving
		var $button = $('.fpr-editing-mode__button button');
		$button.click(function(event) {
			$button.attr('disabled', true);
			$('html').addClass('fpr-util-wait');
			var data = {
				action: 'fpr-editing-mode-save',
				data: {
					postID: fprData.post.ID,
					editingMode: $('.fpr-editing-mode__select').val()
				}
			};
			console.log('~!~ data:', data, ', url: ', fprData.url);
			$.ajax({
				type: 'POST',
				url: fprData.url,
				context: this,
				dataType: 'html',
				data: data,
				complete: function(data) {
					$button.attr('disabled', false);
					$('html').removeClass('fpr-util-wait');
				}
			});
		});

		// Show spinner when saving pending changes
		$('#fpr-pending-revisions-submit').click(function(event) {
			$("#major-publishing-actions .spinner").addClass("is-active");
		});
	});
})(jQuery);
