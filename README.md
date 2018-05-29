# Fabrica Pending Revisions
FPR is a WordPress plugin that enables updates to published Posts to be held in a draft state, or to be submitted for editorial moderation and approval, before they go live. It also works for Pages and Custom Post Types.

It makes WP’s native Revisions more accountable by extending the system’s tracking of changes to taxonomy items and featured images. If Advanced Custom Fields is in use, FPR integrates the plugin’s versioning with WordPress’s own.

Furthermore, FPR improves WP’s ‘Compare Revisions’ screen, making it more transparent and useful for editors.

## Who is this for?

Fabrica Pending Revisions was developed to give WikiTribune’s professional editors oversight of community contributions to news stories, and in particular to mitigate the risks of vandalism by ensuring that malicious edits could be kept away from the public face of the website.

It is therefore suitable for other WordPress-powered publications and websites that need more control than WP offers by default over the publication of content updates.

FPR is useful for organisations where:

- oversight and moderation of content is required by law (or by policy)
- an editorial hierarchy or structure means that not all users / roles are responsible for publishing content or changes, or where not every contributor is automatically trusted

And for websites and blogs where:

- it’s desirable to have a ‘draft’ status for revisions of already-published content, because the author wants to be able to edit content over time, without every intermediate saved change immediately being made public
- the author wants to make changes to content ahead of time, either to defer re-publishing to a future release date, or to make publication easier from a mobile device, or to enable a colleague to publish in the author’s absence

## Features and functionality
- Each post’s ‘accepted revision’ ID is saved (in a hidden custom metafield) to keep track of the currently published revision.
- As well as a post’s title and content, we add hooks to track taxonomies (categories, tags) and featured images per revision so that changes to these fields are also subject to moderation. (By default WordPress does not track them along with revisions, they are committed straight to the main post.) For metadata / custom fields, we currently assume the use of Advanced Custom Fields, which already tracks its fields along with revisions.
- The publish / save button display logic is modified such that:
    - Non-editors can only ‘Suggest edit’, creating a revision that goes into the moderation queue.
    - Editors can both ‘Save pending’, which creates a revision that’s not yet published, and ‘Update’, which will make the current edit public by setting the post’s accepted revision.
- On the front-end, template tags are intercepted so that the accepted revision’s content is shown (everything including title, body, excerpt, taxonomies, featured images, ACF is hooked) – rather than the parent post object’s, as this contains the latest version, which may not yet have been approved.
- The revisions timeline is enhanced to indicate:
    - The currently approved revision.
    - The subsequent revisions (which have not yet been approved).
    - Metadata about each revision: its author, which revision it was based on,
    - Per-revision actions including:
        - ‘Edit’: allows users to use an older revision as the basis of a new edit, in case the latest revision has been vandalised.
        - ‘Preview’: shows how the selected revision looks in the front-end, to allow for an in-context review of suggested changes.
        - (For editors only) ‘Publish’: allows them to select this revision as the currently accepted one.
- The editing mode can be decided per post type (in the plugin’s settings screen): the options are:
    - ‘Open’: all changes are published immediately.
    - ‘Requires approval’: changes by non-editors must be moderated.
    - ‘Locked’: only editors can edit.
- Additionally, the editing mode can be overridden per post, to allow particular stories to be locked temporarily in exceptional circumstances, or conversely, to allow certain stories to be completely open even if the default is ‘requires approval’.

## Installation
Download the zip file and install in WordPress via Plugins > Add New > Upload Plugin, then activate the plugin.

## Changelog
### 0.1.0 (2018-05-29)
- Published as an open-source project on Github, under the MIT license

## Who made this
FPR was originally designed and coded by [Yes We Work](http://yeswework.com/) in conjunction with WikiTribune. [Fabrica](https://fabri.ca/) is a series of tools designed to improve WordPress for content creators and developers.
