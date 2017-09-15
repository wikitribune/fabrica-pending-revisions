(function($) {
	$(function() {

		// Disable update/publish button if post is locked and user is not editor
		if (fpcData.editingMode === 'locked' && !fpcData.canUserPublishPosts) {
			$('#publish').attr('disabled', true);
			$('#major-publishing-actions').show();
		}

		// Handle permissions metabox saving
		var $button = $('.fpc-editing-mode__button button');
		$button.click(function(event) {
			$button.attr('disabled', true);
			$('html').addClass('fpc-util-wait');
			var data = {
				action: 'fpc-editing-mode-save',
				data: {
					postID: fpcData.post.ID,
					editingMode: $('.fpc-editing-mode__select').val()
				}
			};
			console.log('~!~ data:', data, ', url: ', fpcData.url);
			$.ajax({
				type: 'POST',
				url: fpcData.url,
				context: this,
				dataType: 'html',
				data: data,
				complete: function(data) {
					$button.attr('disabled', false);
					$('html').removeClass('fpc-util-wait');
				}
			});
		});

		// Show spinner when saving pending changes
		$('#fpc-pending-changes-submit').click(function(event) {
			$("#major-publishing-actions .spinner").addClass("is-active");
		});
	});
})(jQuery);
