(function($) {
	$(function() {

		// Get accepted revision index in revisions data array
		var revisionData = _wpRevisionsSettings.revisionData,
			acceptedIndex = revisionData.length - 1;
		for (var i = acceptedIndex; i >= 0; i--) {
			revision = revisionData[i];
			if (revision.current) {
				if (revision.pending) {

					// When the last revision is an autosave WP sets the current property in the last non-autosave revision in the data sent to JS (after the `wp_prepare_revision_for_js` hook where we set the correct current revision)
					revision.current = false;
					var revisionModel = wp.revisions.view.frame.model.revisions.models[i];
					if (revisionModel) {
						revisionModel.attributes.current = false;
					}
					continue;
				}
				acceptedIndex = i;
				break;
			}
		}
		if (acceptedIndex >= revisionData.length - 1) { return; }

		// Mark the accepted revision visually
		$('.revisions-tickmarks div:nth-child(' + (acceptedIndex + 1) + ')').css({
			borderLeft: '3px solid #46b450'
		});
		var acceptedPosition = acceptedIndex / (revisionData.length - 1) * 100,
			$pendingChangesTickmarks = $('<span class="fcr-current-revision-tickmark">');
		$pendingChangesTickmarks.css({
			position: 'absolute',
			height: '100%',
			'-webkit-box-sizing': 'border-box',
			'-moz-box-sizing': 'border-box',
			boxSizing: 'border-box',
			display: 'block',
			left: acceptedPosition + '%',
			width: (100 - acceptedPosition) + '%',
			border: 'none',
			background: 'repeating-linear-gradient(-60deg, #ddd, #ddd 9px, #f7f7f7 10px, #f7f7f7 17px)',
			pointerEvents: 'none',
		});
		$('.revisions-tickmarks').prepend($pendingChangesTickmarks);

		// Disable Restore revision button for last revisions
		var sliding = false, sliderData = { value: null, values: null};
		// Values retrieved from Slider are not always up-to-date so get them from the event itself
		$('.wp-slider').on('slide', function(event, ui) {
			sliderData.value = ui.value;
			sliderData.values = ui.values;
		});
		// Detect if button was changed
		var disableLastRevisionRestore = function(event) {
			if (sliding) { return; } // Triggered by a previous call to this function
			sliding = true;
			var $slider = $('.wp-slider'),
				value = sliderData.value !== null ? sliderData.value : $slider.slider('value'),
				values = sliderData.values !== null ? sliderData.values : $slider.slider('values');
			if (values && values.length > 0) {
				value = isRtl ? values[0] : values[1];
			}
			var position = isRtl ? revisionData.length - value - 1 : value;

			if (position == revisionData.length - 1) {
				$('.restore-revision.button').attr('disabled', true);
			}
			sliding = false;
		};
		disableLastRevisionRestore();
		$('.diff-meta-to').on('DOMSubtreeModified', disableLastRevisionRestore);
	});
})(jQuery);
