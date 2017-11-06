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

		// Mark the accepted revision visually
		if (acceptedIndex < revisionData.length - 1) {
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
		}

		// Change columns' header cards
		var $revisionsHeaders = $('<div>', {class: 'revisions-headers'});
		$('.revisions-controls').append($revisionsHeaders);
		var renderColumnHeader = function(side, revision) {

			// Render header fields
			var $header = $('<div>', {
				class: 'revisions-headers__' + side,
				html: [

					// Title
					$('<div>', {
						class: 'revisions-headers__title',
						html: [
							$('<span>', { class: 'revisions-headers__type' }),
							$('<span>', {
								class: 'revisions-headers__id',
								text: 'Revision ID ' + revision.id
							})
						]
					}),

					// Submission date/time
					$('<div>', {
						class: 'revisions-headers__date',
						html: [
							$('<span>', { text: 'submitted ' }),
							$('<span>', {
								class: 'revisions-headers__date-ago',
								text: revision.timeAgo + ' '
							}),
							$('<span>', {
								class: 'revisions-headers__date-long',
								text: revision.date
							})
						]
					}),

					// Author
					$('<div>', {
						class: 'revisions-headers__author',
						html: [
							$('<span>', { text: 'by ' }),
							$('<span>', {
								class: 'revisions-headers__author-name',
								text: revision.author.name + ' '
							}),
							$('<span>', {
								class: 'revisions-headers__author-role',
								text: '(' + revision.author.role + ')'
							})
						]
					}),

					// Note
					((!revision.note && !revision.sourceRevisionID) ? null : $('<div>', {
						class: 'revisions-headers__note',
						html: [
							((!revision.note) ? null : $('<span>', {
								class: 'revisions-headers__note-caption',
								text: 'Note: ' })),
							$('<span>', {
								html: [
									((!revision.note) ? null : $('<div>', {
										class: 'revisions-headers__note-text',
										text: revision.note + ' '
									})),
									((!revision.sourceRevisionID) ? null : $('<div>', {
										class: 'revisions-headers__based-on',
										text: revision.author.role
									}))
								]
							})
						]
					})),
				]
			});

			// Set title class and text according to revision status
			$revisionType = $('.revisions-headers__type', $header);
			if (revision.current) {
				$revisionType.addClass('revisions-headers__type--current')
					.text('Current Published ');
			} else if (revision.pending) {
				$revisionType.addClass('revisions-headers__type--pending')
					.text('Pending ');
			}

			return $header;
		};
		var renderColumnHeaders = function(value, values) {
			var latestRevision = revisionData.length - 1;
			if (!values || values.length <= 0) {
				values = [value - 1, value];
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
