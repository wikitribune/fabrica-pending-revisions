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

		// [TODO] remove (will be rendered obsolete by columns headers)
		// Disable Restore revision button for last revisions
		var sliding = false, sliderData = { value: null, values: null};
		// Values retrieved from Slider are not always up-to-date so get them from the event itself
		$('.wp-slider').on('slide change', function(event, ui) {
			sliderData.value = ui.value;
			sliderData.values = ui.values;
		});
		// Detect if button was changed
		var disableLastRevisionRestore = function(event) {
			if (sliding) { return; } // Triggered by a previous call to this function
			sliding = true;
			var $slider = $('.wp-slider'),
				value = sliderData.value !== null ? sliderData.value : $slider.slider('value'),
				values = sliderData.values !== null ? sliderData.values : $slider.slider('values'),
				latestRevision = revisionData.length - 1;
			if (values && values.length > 0) {
				value = isRtl ? values[0] : values[1];
			}
			var position = isRtl ? latestRevision - value : value;

			if (revisionData[latestRevision].autosave) {
				// Autosave can be restored but lastest revision excluding autosave can't
				latestRevision--;
			}
			if (position == latestRevision) {
				$('.restore-revision.button').attr('disabled', true);
			}
			sliding = false;
		};
		disableLastRevisionRestore();
		$('.diff-meta-to').on('DOMSubtreeModified', disableLastRevisionRestore);

		// Change columns' header cards
		var $revisionsHeaders = $('<div>', {class: 'revisions-headers'});
		$('.revisions-controls').append($revisionsHeaders);
		var renderColumnHeader = function(side, revision) {
			var $revisionType = $('<span>', {class: 'revisions-headers__type'}),
				$revisionID = $('<span>', {class: 'revisions-headers__id'}),
				$revisionInfo = $('<div>', {class: 'revisions-headers__info'}),
				$header = $('<div>', {class: 'revisions-headers__' + side});

			if (revision.current) {
				$revisionType.addClass('revisions-headers__type--current')
					.text('Current Published');
			} else if (revision.pending) {
				$revisionType.addClass('revisions-headers__type--pending')
					.text('Pending');
			}
			$revisionID.text(' Revision ID ' + revision.id);
			$revisionInfo.append($revisionType).append($revisionID);
			$header.append($revisionInfo);

			return $header;
		};
		var renderColumnHeaders = function(value, values) {
			var latestRevision = revisionData.length - 1;
			if (!values || values.length <= 0) {
				values = [];
				values[0] = value - 1;
				values[1] = value;
			}

			var $headerFrom = renderColumnHeader('from', revisionData[values[0]]),
				$headerTo = renderColumnHeader('to', revisionData[values[1]]);
				$revisionsHeaders.empty().append($headerFrom).append($headerTo);
		};
		var $slider = $('.wp-slider');
		$slider.on('slide change', function(event, ui) {
			renderColumnHeaders(ui.value, ui.values);
		});
		renderColumnHeaders($slider.slider('value'), $slider.slider('values'));
	});
})(jQuery);
