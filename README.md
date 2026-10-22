# Listemann Toolkit Addon

WordPress plugin for [listemann.com](https://listemann.com/) by SH Digital. It provides:

- **Elementor widgets** in the panel category **Listemann**: *Continuous Slider*, *Listemann Menu*, *Listemann Header*, *Listemann Footer*, *Listemann Downloads* and *Listemann Posts*;
- three **site-wide features** that appear on every front-end page without any widget: a **contact box** and a **back-to-top button** docked to the right edge, and a **scrollbar in the brand colour**. They are edited under **Listemann Toolkit** in the dashboard.

## Requirements

- WordPress 6.5+ and PHP 7.4+.
- Elementor 3.24+ for the widgets (developed and tested with Elementor 4.3.5 and the Hello Elementor theme). The widgets use the classic widget API (`\Elementor\Widget_Base`), so they work with and without the Elementor V4 / atomic editor experiments. Elementor Pro is optional: it is only needed to use the header in a Theme Builder header template.
- No build step. The front-end scripts are plain JavaScript without jQuery or third-party libraries.

## Installation

1. Build the installable zip from the repository. `.gitattributes` keeps the development files out of it:

   ```sh
   git archive --format=zip --prefix=listemann-toolkit-addon/ -o listemann-toolkit-addon.zip HEAD
   ```

2. In WordPress go to **Plugins › Add New Plugin › Upload Plugin**, upload the zip and activate it. Or copy the folder to `wp-content/plugins/listemann-toolkit-addon/` and activate it there.

The contact box, back-to-top button, scrollbar and settings page work without Elementor. Only the widgets need it. If Elementor is missing, inactive or older than 3.24, the widgets are not registered and an admin notice offers to install, activate or update Elementor.

The plugin header contains `Update URI: false`, so wordpress.org never offers an update for this plugin, even if a plugin with the same folder name is published there. Install new versions by uploading a new zip.

## Continuous Slider

Elementor panel: **Listemann › Continuous Slider** (search: *slider*, *carousel*, *marquee*). It recreates the full-width "Einsatzgebiete" image strip from the homepage. Previously the strip was a theme shortcode rendered with slick.

### Recreating the homepage strip

1. Add a container and set **Content Width › Full Width** and **Padding 0**. For a legacy section, turn **Stretch Section** on, set **Columns Gap › No Gap** and give the column padding 0. The slider always fills its parent, so the parent sets its width.
2. Add the heading "Einsatzgebiete" above it as a separate Heading widget, as on the original page.
3. Drop the **Continuous Slider** into the container. The defaults match the original:
   - 4 slides (Luft- & Raumfahrt, Energie- & Umwelttechnik, Medizintechnik, Formen- & Werkzeugbau), 7 per view, 15 px gap, 550 px high (300 px on tablet and mobile);
   - a 35 % black overlay that turns 75 % red (#dd191a) on hover, and white titles at the bottom left;
   - on desktop and tablet the strip glides left continuously at one slide every 6 s; on mobile it steps one slide every 3.5 s and can be swiped.
4. Pick the image and link of each slide. Don't duplicate slides: the script clones them as often as the screen width requires.

### Settings

| Section | Control | Default | Notes |
| --- | --- | --- | --- |
| Content › Slides | Slides (image, title, link) | 4 slides | Image, title and link accept dynamic tags. |
| | Image Resolution | Large | Registered sizes get `srcset`. |
| | Title HTML Tag | `span` | `span`, `div`, `p`, `h2`–`h6`. |
| Content › Slider | Slides per View ⧉ | empty / tablet 4 / mobile 2 | Empty on desktop follows the original: 7 slides, 5 below 1300 px, 4 below 1100 px. A desktop value replaces these steps. Decimals (e.g. 2.5) let the next slide peek in. |
| | Gap ⧉ | 15 px | |
| | Gap at the Edges ⧉ | Half gap | *Half gap*: each slide plus its gap takes exactly 1 / slides per view of the width, as on the original. *None (flush)*: the outer slides touch the edges. |
| | Height ⧉ | 550 px / tablet 300 px / mobile 300 px | px, vh, em, rem. |
| | Motion ⧉ | Continuous / mobile Step | Continuous glide, Step (one slide at a time), Static. |
| | Direction | Left | Applies to continuous and step motion. |
| | Continuous: Seconds per Slide | 6 | Time one slide plus its gap takes to pass. |
| | Continuous: Pause on Hover / Allow Drag | off / off | The glide eases out and back in when it pauses. |
| | Step: Interval / Transition Duration | 3500 ms / 300 ms | |
| | Step: Pause on Hover / Allow Drag | on / on | |
| | Static: Allow Drag | off | |
| | Buttons: Pause Button | on | A pause / play button while the strip moves on its own (WCAG 2.2.2). |
| | Buttons: Previous / Next Buttons | on | Shown while the strip stands still (paused, static or reduced motion) and not every slide fits. |
| | Buttons: Show Buttons | Always | Or *On hover and keyboard focus*; touch screens always show them. |
| | Accessible Label | Einsatzgebiete | Name of the carousel region for screen readers. |
| Style › Slides | Border Radius ⧉, Image Position ⧉ | –, center center | |
| | Image Height ⧉ | 109 % / tablet 100 % | Relative to the slide height. A taller image is cropped at the bottom; the original shows 600 px images in 550 px slides. The image never gets shorter than the slide. |
| | CSS Filters (normal / hover) | – | |
| Style › Overlay | Color / Opacity (normal, hover), Transition Duration | #000 35 %, #dd191a 75 %, 300 ms | Hover and keyboard focus apply to the whole card. |
| | Shade Behind Title, Shade Color | 0, #000 | A gradient over the lower half of the slide; about 0.6 keeps white titles readable on light images. |
| Style › Title | Color (normal, hover), Typography, Text Shadow, Padding ⧉, Vertical Position ⧉, Alignment ⧉ | #fff; 1.3 / 2.2 / 4.2 vw; line height 1.7 / 2.8 / 6.5 vw; 0.5 px letter spacing; bottom | |
| Style › Buttons | Position ⧉, Icon Color and Background Color (normal, hover) | top right; #fff on 60 % black, hover on #dd191a | |

⧉ = responsive control.

### Breakpoints

With **Slides per View** left empty on desktop, the strip follows the steps of the original slick slider: 7 slides, 5 below 1300 px and 4 below 1100 px; Elementor's Tablet (1024 px) and Mobile (767 px) values (4 and 2) apply below that. A value on desktop replaces the 1300 / 1100 px steps. For other steps, enable more breakpoints (e.g. **Laptop**) under *Site Settings › Layout › Breakpoints* and set a value there. All layout values are CSS custom properties written by Elementor, so custom breakpoints work without code changes.

### Behaviour and accessibility

- **Rendering.** The server renders every slide once. Without JavaScript the first slides show as a static strip without a scrollbar.
- **Clones.** The script adds clones until the strip covers the viewport plus one full set. Clones are hidden from screen readers (`aria-hidden`). Their content is `inert`, and focusable elements also get `tabindex="-1"` for browsers without `inert`. The clone's slide element itself still receives the pointer: hover styles apply to it, and a click on a clone is forwarded to the original card.
- **Pausing.** Motion pauses while the slider is off-screen or the browser tab is hidden.
- **Pause and navigation buttons.** While the strip moves on its own, a pause button lets visitors stop and restart it. While it stands still and not every slide fits, previous / next buttons move it by one slide.
- **Reduced motion.** Visitors with `prefers-reduced-motion: reduce` get no autoplay. They can drag the strip or use the previous / next buttons, and keyboard focus still brings slides into view.
- **Keyboard.** When a slide link receives keyboard focus, the slider stops and moves that slide fully into view. It resumes when focus leaves the slider.
- **Dragging.** A press that moves more than 6 px counts as a drag and does not open the link. Native image and link drag ghosts are disabled.
- **Screen readers.** The region is announced as a carousel with the Accessible Label, and each slide as a slide with its position ("1 of 4"). A slide with a visible title gets an empty image `alt`, because the title already names the link. A slide without a title uses the image's alt text from the media library.

### JavaScript API

The engine is available as `window.ListemannContinuousSlider` and also runs without Elementor:

```js
const slider = new ListemannContinuousSlider( document.querySelector( '.lst-cslider' ), {
	continuous: { speed: 4 }, // overrides data-lst-options
} );
slider.setPaused( true ); // stops the autoplay like the pause button; setPaused( false ) restarts it
slider.refresh(); // after changing the slider's CSS
slider.destroy(); // removes clones, listeners and observers
ListemannContinuousSlider.initAll(); // all .lst-cslider without an instance
```

## Listemann Menu

Elementor panel: **Listemann › Listemann Menu**. It renders a WordPress menu (*Appearance › Menus*) as grouped links, like the listemann.com footer: top-level items act as headings and their sub-items are links with a **small red line** in front.

- **Content › Menu:** Menu, Depth (all levels or 1–3), Layout (*Vertical*, or *Horizontal* for a row of top-level items such as legal links, with gap and alignment), *Top-level items are headings* (on), *Show "#" links as plain text* (on; items with an empty or `#` URL become text instead of links) and an Accessible label for the navigation landmark (empty = menu name).
- **Style › Headings (top level)** and **Style › Items (sub levels):** typography, normal / hover / active colours, spacing between groups, spacing below headings and between items, indent per nested level, and the font size and line height of the 3rd level and deeper. Defaults: headings 18 px bold uppercase, items 15 px, both #626262 on a 34 px line; items turn red (#dd191a) on hover and for the current page.
- **Style › Line before items:**

  | Control | Default |
  | --- | --- |
  | Show line | on |
  | Apply to | Sub-menu items (or All items, Top-level items) |
  | Width ⧉ / Width (nested items) ⧉ | 30 px / 20 px |
  | Thickness, Color | 2 px, #dd191a |
  | Gap to text ⧉, Border radius | 5 px, 0 |
  | Vertical offset | 0; the line is centred on the first line of text, so wrapped items keep it in place |
  | Hover effect, Grow by, Hover color | None (or Grow, Color, Grow and color), 10 px, #626262 |

The current page gets `aria-current="page"` and the active colour. The line is decorative CSS (`::before`), so screen readers do not announce it.

## Listemann Header

Elementor panel: **Listemann › Listemann Header**. It recreates the listemann.com header: a grey logo box that hangs over the content, the main menu with dropdowns and mega menus, a language switcher, and a hamburger that opens a full-screen off-canvas menu.

### Where to place it

- **With Elementor Pro:** *Templates › Theme Builder › Header*, add a container with **Content Width › Full Width** and padding 0, drop the widget into it, publish and set the display condition to *Entire Site*.
- **Without Elementor Pro:** put the widget in a full-width container at the top of a page. To hide the theme's own header, either set the page template to *Elementor Canvas* or, with Hello Elementor, turn on *Hello › Settings › Disable theme header and footer*.

### Content

| Section | Controls (defaults) |
| --- | --- |
| Logo | Logo image (site logo from the Customizer, otherwise the bundled Listemann logo), Link (home page), Alternative text (site name), Logo width ⧉ (165 / 65 / 65 px). |
| Main menu | Menu; Mega panel width (*Auto* or *Full* header content width); Show dropdown chevrons (on); Open dropdowns on (*Hover*, or *Click*: the first click opens, the second follows the link); Accessible label (empty = menu name). |
| Language switcher | Show language switcher (on), Display (*Flag + code*, *Code only*, *Flag only*), Also show in the off-canvas (on). |
| Hamburger / off-canvas | Show hamburger ⧉ (on; below the collapse breakpoint it is always shown, because it is then the only way to the menu); Off-canvas content: *Menus* (Menu columns, one menu per column with an optional accessible label, by default the first three menus; Show logo; Off-canvas logo, by default the colour logo; Social links: LinkedIn, Facebook, YouTube, Instagram, Xing, X or a custom icon, by default the three Listemann profiles) or *Elementor template* (any published saved template that does not contain this header); Accessible label of the dialog (empty = "Menu"). |
| Layout | Collapse breakpoint (1280 px): below this screen width the main menu and language switcher are hidden and only the logo and hamburger remain; 0 = never collapse. Sticky header (off): once the header has scrolled out of view, a compact bar (90 / 60 / 60 px, logo 110 / 65 / 65 px) stays fixed at the top, below the admin bar, with optional background and shadow. Sticky does not work inside a parent that uses CSS transforms. |

The Style tab has sections for the header bar (height 170 / 60 / 60 px, background, side padding, max content width), the logo box (#eee, 234 × 200 px on desktop), top-level items, dropdowns (including the group headings and the red line before items), the language switcher, the hamburger and the off-canvas.

### Mega menus

Second-level items that have sub-items are shown as column headings with their sub-items beneath. To turn a dropdown into a **multi-column mega panel**, open *Appearance › Menus*, expand the **top-level** item and set **Mega menu columns (Listemann Header)** to 2–6; each second-level item then becomes a column. Leave the field empty or set 0 for a normal dropdown. The value is stored in the item's post meta `_lst_mega_columns`. The field is only on the *Appearance › Menus* screen, not in the Customizer's menu panel.

### Language switcher

The switcher reads its languages from **WPML** (`wpml_active_languages`) or **Polylang** (`pll_the_languages()`). It is shown only when two or more languages exist, and it is skipped when WPML or Polylang already add language items to the selected menu. The filter `listemann_toolkit_header_languages` can change the list (entries: `code`, `name`, `url`, `flag`, `current`).

### Behaviour and accessibility

- Dropdowns open on hover (with a 150 ms close delay) and on keyboard focus. Every parent item has a real toggle button with `aria-expanded`; a top-level item with a `#` link is itself the button. Escape closes and returns focus. On touch devices the first tap opens the dropdown. A dropdown that would overflow the right edge of the viewport opens to the left.
- The off-canvas is a modal dialog (`role="dialog"`, `aria-modal`) moved to the end of `<body>`. Focus moves into it and stays there while it is open. Page scrolling is locked without a layout shift. Escape, the close button and same-page links close it, and focus returns to the hamburger. When closed it is `inert`.
- The current page and its ancestors get the active colour. Reduced motion turns off the transitions.
- The script runs without Elementor's frontend script (`window.ListemannHeader.init( root )`, `.destroy( root )`, `.initAll()`) and cleans up when the Elementor editor re-renders the widget.

## Listemann Footer

Elementor panel: **Listemann › Listemann Footer** (search: *footer*, *fusszeile*). It recreates the listemann.com footer: the colour logo with the social icons below it, then the footer menus in columns (top-level items as headings, sub-items with the **red line**, exactly like the Listemann Menu widget), spread across the width. Below 1280 px the logo and the icons share the first row (logo left, icons right) and the menus wrap into 2 columns; on phones into 1. The hidden newsletter form of the original is not part of it.

### Where to place it

- **With Elementor Pro:** *Templates › Theme Builder › Footer*, add a container (boxed, content width 1180 px, side padding 50 px, 15 px on mobile, like the original), drop the widget into it, publish and set the display condition to *Entire Site*. The template provides the `<footer>` landmark.
- **Without Elementor Pro:** put the widget at the end of a page in such a container and set the container's *Layout › Additional Options › HTML Tag* to *footer*, so the page keeps its footer landmark (the theme's own footer can be hidden with *Elementor Canvas* or Hello Elementor's *Disable theme header and footer*).

### Settings

| Section | Controls (defaults) |
| --- | --- |
| Content › Logo | Show logo (on), Logo image (the bundled colour logo), Link (home page), Alternative text (site name), Logo width ⧉ (125 px). |
| Content › Social links | Social links: LinkedIn, Facebook, YouTube, Instagram, Xing, X or a custom icon, each with a link and an accessible label (the three Listemann profiles, as in the header); Open in New Tab (on, announced to screen readers). |
| Content › Menu columns | Menu columns: one menu per column with an optional accessible label (empty: the menu's headings, e.g. "Verfahren, Einsatzgebiete"). On desktop four columns fit next to the logo in the 1180 px container; from five on, the columns that do not fit continue on a second row. Preset: the menus named "Footer 1", "Footer 2" … in that order (at most four); without numbered ones the menus whose names start with "Footer" (or "Fusszeile"); otherwise none, with a hint linking to *Appearance › Menus*. Depth (all levels or 1–3). |
| Content › Bottom bar | Show bottom bar (off; not on listemann.com): Text (`© {year} Listemann AG`, `{year}` becomes the current year), Legal links menu (its top-level items in a row), accessible label (empty: "Legal links"). |
| Style › Footer | Background color (#fff), Padding ⧉ (empty: 150 px above and below; 75 / 75 px below 1280 px; 75 / 50 px on mobile, like the original; the original's 400 px below 1280 px only made room for its hidden newsletter form and is not copied). |
| Style › Layout | Column gap ⧉ (empty: at least 30 px on desktop, none below 1280 px where the menus share the width equally), Row gap ⧉ (empty: 25 px, 30 px between the menus on mobile), Columns below 1280 px (empty: 2), Columns below 768 px (empty: 1). |
| Style › Social icons | Color (#949494, see below), Hover color, Icon height (20 px at every width; the original blew the icons up on phones), Space beside each icon (10 px), Space above the icons (50 px on desktop), Hover zoom (1.25). |
| Style › Menu headings / Menu items / Line before items | The same controls and defaults as the header's off-canvas columns: headings 18 / 700 / 34 px uppercase, items 15 / 34 px, both #626262, hover and current page #dd191a, 25 px between groups; line 30 × 2 px #dd191a, 20 px on nested levels, 5 px to the text. |
| Style › Bottom bar | Typography (14 / 24 px), Color (#626262), Link hover color (#dd191a), Top border width and color (1 px, #e5e5e5), Space above (50 px), Padding ⧉ (25 px above). |

⧉ = can be set per device. Values left empty follow the original's steps at 1280 px and 768 px; a desktop value also applies to tablet and mobile unless they have their own.

### Accessibility

- The widget renders a `<div class="lst-footer">`; the `<footer>` landmark is the theme's or the Theme Builder template's (on a page without either, set the surrounding container's HTML Tag to *footer*). Each menu column is a `<nav>` labelled with the column's accessible label or else with the menu's headings ("Verfahren, Einsatzgebiete"; the menu name if it has none), the legal links with "Legal links" unless set otherwise. Items with an empty or `#` URL are text, and ids are unique, also with several footers on a page.
- The social icons are a list of links named by their network ("LinkedIn (opens in a new tab)"); the icons themselves are hidden from screen readers. listemann.com uses #a8a8a8 for them, which has a contrast of 2.4:1 on white; the default #949494 is the lightest grey with the 3:1 that icons need (as in the header).
- Keyboard focus shows a 2 px outline (the icons also zoom), reduced motion turns the zoom transition off, forced colours (Windows High Contrast) keep the red lines visible, and print hides the icons and drops the large padding.

The output depends on the current page (the current menu item) and the date, so Elementor never caches it.

## Listemann Downloads

Elementor panel: **Listemann › Listemann Downloads** (search: *downloads*, *pdf*, *ressourcen*). It recreates the "Ressourcen" list of [listemann.com/downloads/](https://listemann.com/downloads/): a heading, then groups of download cards, each with a group title. Each card is a white box with a red bar on the left, a file icon, a small label ("PDF DOWNLOAD") and the title; on hover it zooms in. The defaults are the live page's 5 groups and 16 PDFs, so the page can be rebuilt by dropping the widget into a boxed container (content width 1200 px, side padding 50 px, 15 px on mobile, like the original page).

### Groups

Elementor has no nested repeaters, so **Content › Downloads** is one list of items with a **Type**:

- **Group title** starts a group. The downloads after it, up to the next group title, belong to it. The rows read "▸ Zertifikate Schweiz", with the downloads indented below.
- **Download**: Title, Source (*Media Library* file or *URL*, with the URL control's new window, nofollow and custom attributes), an optional Label (replaces the label template, `{type}` allowed) and an optional Custom Icon (empty: a PDF icon for PDFs, a generic document icon for other files).

Downloads above the first group title form a group without a title. On the live page, groups without downloads are left out, and a download without a file or URL is shown as a card that is not a link. In the editor both show a hint.

### Settings

| Section | Controls (defaults) |
| --- | --- |
| Content › Heading | Show Heading (on), Heading ("Ressourcen", dynamic), HTML Tag (H2). |
| Content › Options | Label Template (`{type} Download`: `{type}` becomes the file extension in capitals, PDF / DOCX / ZIP, from the URL without its query string, or "File" when there is none or the address is a script such as `.php`), Open in New Tab (on), Save Instead of Opening (on; the `download` attribute: files on this site are then saved and do not open a tab, files on other domains open as usual because browsers ignore the attribute there), Show File Size (off; Media Library files, from the attachment metadata), Group Title HTML Tag (H3: one level below the heading; the original page used H4). |
| Style › Layout | Columns ⧉ (empty: 3, 2 below 1280 px, 1 on mobile), Column Gap ⧉ (36 px), Row Gap ⧉ (36 px; half of it is also kept above and below each group's cards), Space Between Groups ⧉ (empty: 100 px, 50 px below 1280 px; also below the heading), Side Spacing ⧉ (empty: 18 px, 0 on mobile). |
| Style › Heading / Group Titles | Color (#dd191a / #626262), Typography (42 / 300 / 62 px and 30 / 300 / 44 px), heading Margin ⧉, group title Spacing Below ⧉ (16 px). |
| Style › Cards | Min Height ⧉ (110 px), Padding ⧉ (10 / 25 / 10 / 25 px; the icon sits in its top left corner), Border Radius ⧉, Normal / Hover: Background Color (#fff), Box Shadow (20 px 20 px 46 px rgba(0,0,0,.15)), Accent Bar Color (#dd191a); Accent Bar (Left, Top or None) and its Width (10 px); Hover Effect (*Zoom* 1.11, *Lift*, *Stronger shadow*, *None*), Zoom Factor, Transition Duration (300 ms), Focus Outline Color (#dd191a). |
| Style › Icon / Label / Title | Icon Width ⧉ (20 px; the bundled icons are 20 × 27 px), Spacing to Text ⧉ (15 px), Vertical Offset ⧉, Color (#d9d9d9) and Hover color; Label Typography (14 / 28 px, uppercase), Color (#626262), Hover Color; Title Typography (18 / 300 / 28 px, 17 px on mobile), Color (#dd191a), Hover Color, Title Position (*under the icon below 1280 px* like the original, *always next to the icon*, *always under the icon*). |

⧉ = can be set per device. Layout values left empty follow the original's steps at 1280 px and 768 px; a desktop value also applies to tablet and mobile unless they have their own.

### Accessibility

- Every card is a single link; its accessible name is the label, the title and, in screen-reader text, the file type (when the label does not show it) and "(opens in a new tab)", e.g. "PDF Download ISO9001 2027 DE (opens in a new tab)". The label is written as typed and only shown in capitals by CSS. The icons are hidden from screen readers. (The original page had empty links stretched over the cards.)
- Each titled group is a `<section>` labelled by its title; the cards are a list. Ids are unique, also with several Downloads widgets on a page.
- Keyboard focus shows a 2 px outline and the same zoom as hover. Touch screens get no zoom, reduced motion turns the transition off, forced colours (Windows High Contrast) show the card borders, and print drops the shadow and the zoom.

## Listemann Posts

Elementor panel: **Listemann › Listemann Posts** (search: *blog*, *posts*, *news*, *aktuelles*). It recreates [listemann.com/blog/](https://listemann.com/blog/): a heading, a category filter, post cards and a red **MEHR LADEN** button. Each card shows the featured image under a 35 % dark overlay, the date ("07. FEBRUAR 2026"), the title and the author at the bottom left; the title turns red on hover. The default *Featured* layout is the original's: one wide card next to two stacked cards, then rows of three (one column on mobile). The first page shows 9 posts, each click on the button adds 6.

Improvements over the original page:

- Long titles never run into the author line (the card keeps room for it; titles are clamped to 4 lines by default, with the full title still the link text).
- Each card is one link (the title, stretched over the card), so hover and keyboard focus work on the whole card. Images are real `<img>` elements with `srcset` and lazy loading (the first card loads eagerly).
- The filter is a group of real checkboxes. Without JavaScript it is a form (`?lst_cat[]=…`) and "load more" is a link to the next page (`?lst_page=2`), so both also work for search engines and keyboard users; with JavaScript they update the cards in place, keep the URL in sync and announce the result to screen readers.
- Instead of the red title (hard to read on dark photos) the hover effect can be an image zoom or a darker overlay.

### Settings

| Section | Controls (defaults) |
| --- | --- |
| Content › Heading | Show Heading (on), Heading ("Aktuelle Informationen der Listemann Gruppe"), HTML Tag (H2: the page usually has its own H1). |
| Content › Query | Source (*Posts* = own query; *Current query* for Elementor Pro archive templates, which then uses the archive's page size and pagination, without the filter), Post Type (Posts), Categories / Exclude Categories / Tags / Authors (empty: all), Order By (Date) and Order (descending), Posts on the First Page (9), Posts per "Load More" / Page (6), Offset (0), Exclude the Current Post (on), Ignore Sticky Posts (off: sticky posts come first), Only Posts With a Featured Image (off). |
| Content › Layout | *Featured* (1 wide + 2 stacked, then rows), *Grid* (all cards equal) or *List* (image left, text right); columns, gaps and card heights ⧉ follow the original's steps at 1280 px and 768 px when left empty. |
| Content › Card Content | Show Date (format `d. F Y`, month names in the site language, or the site setting), Show Author, Show Profile Picture (off), Show Categories (off), Show Excerpt (off, 20 words), Title HTML Tag (H3), Maximum Title Lines (4; 0 = no limit), Fallback Image for posts without one, Image Resolution (Large), Open Posts in a New Tab (off). |
| Content › Filter | Show Category Filter (on), Label ("Filter:"), categories in the filter (all categories of the query, or a selection), several categories (checkboxes, posts in any of them) or one (radio buttons, with an "All" option), Show Post Counts (off), Hide Empty Categories (on). |
| Content › Load More | Pagination (*"Load more" button*, *Page numbers* or *None*), Button Text ("Mehr laden"), Text While Loading, Text When All Posts Are Shown (empty: the button disappears), Text When No Posts Match. |
| Style | Heading, Filter (label, categories, checkbox size, border, check mark), Cards (radius 10 px, background #cfcfcf, overlay colour and opacity 0.35, hover effect *Title turns red* / *Image zoom* / *Darker overlay* / *None*, focus outline colour), Date, Title (24 / 36 px, 18 / 33.6 px below 1280 px), Author (16 px, avatar size, position), Categories and Excerpt, Load More Button (20 px, 3 px letter spacing, 17 px / 105 px padding, full width on mobile). |

⧉ = can be set per device.

### How "load more" and the filter load posts

The script asks the REST route `listemann-toolkit/v1/posts` for the next page, naming only the page that contains the widget, the widget's id, the page number and the ticked categories. The server rebuilds the query from the settings **saved** in that page, only lists published posts of public post types, ignores categories the filter doesn't offer, and refuses pages that the visitor can't see (drafts, private or password-protected pages). Save the page before trying the filter and "load more" in the Elementor preview.

## Site-wide features and the settings page

These features are added automatically to every front-end page (also in the Elementor preview), but not in wp-admin, feeds, embeds, REST or AJAX responses. Their assets load only while the feature is enabled.

- **Contact box:** a red button with a phone and envelope icon on the right edge. A click slides in the red contact panel. Escape, a click outside or moving focus out of the panel closes it. The closed panel is `inert`. While closed the panel is 200 px high (button 200 px above the bottom, as on listemann.com); opened, it grows with its content up to the screen height. The button stays attached to the panel's top-left corner. On screens up to 767 px the panel is 250 px wide.
- **Back to top:** a grey arrow button directly below the contact button. On hover or keyboard focus the label ("Zurück nach oben") slides in. A click scrolls to the top (smoothly unless the visitor prefers reduced motion) and moves keyboard focus to the start of the page.
- **Scrollbar:** the page scrollbar in the brand colour (5 px wide by default). Chrome, Edge and Safari use the exact width. Firefox only supports *thin* (used up to 8 px) and *auto*. Only the page scrollbar is styled; scrollbars inside the page keep the browser default.

The dock is hidden when printing. Both can also be turned off in code with the filters `listemann_toolkit_side_dock_enabled` and `listemann_toolkit_scrollbar_enabled`.

**Dashboard › Listemann Toolkit** (capability `manage_options`; also linked as *Settings* on the Plugins screen):

| Section | Fields |
| --- | --- |
| Contact box | Enable; Button label for screen readers ("Kontakt"); Sections, each with a Title (shown in capitals), a Text size (*Large* like phone numbers, 19 px, or *Small* like addresses, 15 px) and Lines (Text, Link URL, Open in new tab). Sections and lines can be added, removed and moved up and down. The defaults are the original TELEFON and ANFAHRT sections. |
| Back to top | Enable; Label (each line is a line of the label); Show after scrolling (px, 0 = always visible). |
| Appearance | Brand color (#dd191a, used by the contact box, its button and the scrollbar), Text color on brand color (#fff), Back-to-top button color (#a8a8a8, the listemann.com grey; its description recommends #8c8c8c or darker for the 3:1 contrast of WCAG 1.4.11), Back-to-top arrow color (#fafafa). |
| Scrollbar | Enable; Color (empty = brand color); Width (2–24 px). |

Links accept `tel:`, `mailto:`, `http(s)://` and relative URLs. A bare phone number or e-mail address becomes a `tel:` or `mailto:` link automatically, and spaces, separators and a "(0)" are removed from phone links (`+41 (0)52 245 18 50` → `tel:+41522451850`). A line without text shows its address. Empty lines and sections are dropped on save. **Reset to defaults** at the bottom of the page deletes the stored settings, so the defaults apply again.

All settings live in the single option `listemann_toolkit_settings`. The option is only created when the page is saved for the first time; until then the defaults apply.

## Translation and multilingual sites

- **Plugin texts.** The editor labels, admin screens and the texts read by screen readers (e.g. "Open menu", "carousel", "(opens in a new tab)") are translatable with the text domain `listemann-toolkit-addon`. A German translation (`de_DE`) ships in `languages/`; with a German admin language the widgets are listed as *Endlos-Slider*, *Listemann Menü*, *Listemann Header*, *Listemann Footer*, *Listemann Downloads* and *Listemann Beiträge*. Other languages can be added with Loco Translate or Poedit from `languages/listemann-toolkit-addon.pot`. Elementor caches the slider's output (by default for 24 hours), so after adding or changing a translation, clear it with *Elementor › Tools › Clear Files & Data*.
- **Contact box and back-to-top texts (WPML).** `wpml-config.xml` registers the button label, section titles, line texts and URLs, and the back-to-top label as admin texts. **Save the Listemann Toolkit settings page once** (WPML can only register an option that exists, see above), then translate the strings under *WPML › String Translation*, domain *admin_texts_listemann_toolkit_settings*. After *Reset to defaults*, save the page again before translating. Polylang reads the same file and lists the strings under *Languages › Translations*.
- **Widget content** is stored in each page's Elementor data and translated with the page. `wpml-config.xml` registers the widgets' texts, so WPML's Translation Editor lists them: the slide titles and links and the Accessible Label of the slider, the accessible label of the menu, the logo link and alternative text, accessible labels and social links of the header and of the footer, the footer's bottom bar text, the heading, label template, group titles, download titles, labels and URLs of the downloads widget (a Media Library file is not a text and is not listed), and the heading, custom date format, filter texts and button texts of the posts widget. Selected menus and templates are not texts: check on every language version that the header, footer and menu widgets show the menus of that language.

## Uninstall

Deleting the plugin on the Plugins screen runs `uninstall.php`, which deletes the option `listemann_toolkit_settings` and the *Mega menu columns* values of the menu items (post meta `_lst_mega_columns`), on multisite on every site. Deactivating it keeps both. Elementor content that uses the widgets stays in the database.

## Development

```
listemann-toolkit-addon.php              Plugin header, constants, PHP check, autoloader, bootstrap
includes/class-plugin.php                Plugin: starts the global features, Elementor checks, category, widget registry, widget assets
includes/class-settings.php              Settings: option name, defaults, get(), sanitize()
includes/class-mega-menu-fields.php      "Mega menu columns" field on Appearance › Menus
includes/class-posts-rest.php            REST route of Listemann Posts (filter and "load more")
includes/admin/class-settings-page.php   Dashboard page "Listemann Toolkit" (Settings API, reset, Plugins link)
includes/frontend/class-side-dock.php    Contact box + back-to-top button (wp_footer)
includes/frontend/class-scrollbar.php    Branded scrollbar (inline CSS)
includes/widgets/class-*.php             One file per widget, plus the menu walkers
includes/widgets/trait-widget-helpers.php  Shared by header and footer: logo image, social links, menu columns, style control helpers
assets/css, assets/js, assets/img        Front-end and admin assets, icons, logos
assets/img/file-*.svg                    File icons of Listemann Downloads (PDF and generic, currentColor)
languages/                               .pot template and the German translation (text domain listemann-toolkit-addon)
wpml-config.xml                          Translatable admin texts and widget texts for WPML / Polylang
uninstall.php                            Deletes the settings option and the mega menu item meta
```

Classes live in the `Listemann_Toolkit` namespace. The autoloader maps namespaces to folders and class names to WordPress-style file names: `Listemann_Toolkit\Widgets\My_Widget` → `includes/widgets/class-my-widget.php`, `Listemann_Toolkit\Frontend\My_Feature` → `includes/frontend/class-my-feature.php`; traits use `trait-*.php` (`Listemann_Toolkit\Widgets\Widget_Helpers` → `includes/widgets/trait-widget-helpers.php`).

### Adding a widget

1. Create `includes/widgets/class-my-widget.php` with the class `Listemann_Toolkit\Widgets\My_Widget` extending `\Elementor\Widget_Base`. Return `\Listemann_Toolkit\Plugin::CATEGORY` from `get_categories()`.
2. Add `Widgets\My_Widget::class` to `Plugin::WIDGETS` in `includes/class-plugin.php`. `register_widgets()` skips classes that do not exist, so a wrong namespace or file name makes the widget silently disappear from the panel.
3. If the widget needs assets, add them to `Plugin::SCRIPTS` / `Plugin::STYLES` (handle → path and dependencies, handles prefixed `listemann-toolkit-`). Return the handles from the widget's `get_script_depends()` / `get_style_depends()`, so Elementor loads them only on pages that use the widget.

Conventions used by the existing widgets, worth keeping:

- **Inner wrapper.** `has_widget_inner_wrapper()` returns `false` only when Elementor's optimized markup is active, because the Advanced tab relies on the wrapper otherwise. Write CSS that works both with and without `.elementor-widget-container`.
- **Values read in `render()`.** Controls with `selectors` are not available in `render()` on the front end (Elementor's optimized control loading). Values the PHP or JS needs go into plain controls or `data-*` attributes. Purely visual values go into CSS custom properties.
- **Caching.** `is_dynamic_content()` must return `true` when the output depends on the request (current menu item, language). Otherwise Elementor caches it per page.
- **Front-end handlers.** Front-end JS attaches with `elementorFrontend.elementsHandler.attachHandler( '<widget-name>', Handler )` on the `elementor/frontend/init` event. The handler extends `elementorModules.frontend.handlers.Base`. Don't use class fields: `onInit()` runs inside the base constructor.

### Adding a global feature

Global features must not depend on Elementor.

1. **Settings.** Add a group with its defaults to `Settings::defaults()` and sanitize it in `Settings::sanitize()`. `Settings::get()` only returns keys that exist in the defaults.
2. **Fields.** Add a section and fields in `Admin\Settings_Page::register_settings()` (`add_settings_section()` plus `self::add_field( $group, $key, $label, $type, $args )` with the types `checkbox`, `text`, `textarea`, `number` or `color`).
3. **Front end.** Create `includes/frontend/class-my-feature.php` with `final class My_Feature` in `Listemann_Toolkit\Frontend` and a `public static function init()` that adds the hooks (e.g. `wp_enqueue_scripts`, `wp_footer`). Check the setting before enqueueing anything, skip admin, feed and REST requests like `Side_Dock::is_enabled()`, and offer a `listemann_toolkit_*_enabled` filter.
4. **Bootstrap.** Call `Frontend\My_Feature::init();` in `Plugin::__construct()` next to `Side_Dock::init()`, before the Elementor check.
5. **Translation.** Add translatable option texts to `wpml-config.xml`. A feature that stores its data outside `listemann_toolkit_settings` must also delete it in `uninstall.php`.

### Translations

After changing strings, regenerate the template and update the German files with WP-CLI from the plugin folder:

```sh
wp i18n make-pot . languages/listemann-toolkit-addon.pot --exclude=.claude
wp i18n update-po languages/listemann-toolkit-addon.pot languages/listemann-toolkit-addon-de_DE.po
# translate the new entries in the .po file, then:
wp i18n make-mo languages/listemann-toolkit-addon-de_DE.po languages/
```

### Asset versions

With `SCRIPT_DEBUG` set to `true`, the widget assets registered by `Plugin` get the file modification time as their version, which busts browser caches while developing. The side dock, scrollbar and settings page assets always use the plugin version (`LISTEMANN_TOOLKIT_VERSION`).

Bump `Version` and `LISTEMANN_TOOLKIT_VERSION` in `listemann-toolkit-addon.php` for every release, so browsers and caching plugins load the new CSS and JS.

## Changelog

- **1.0.1**: Header: hovering or focusing a dropdown arrow no longer shifts the menu (the menu jittered while the pointer rested on an arrow).
- **1.0.0**: First release: Continuous Slider, Listemann Menu, Listemann Header, Listemann Downloads, Listemann Posts and Listemann Footer widgets; contact box, back-to-top button and branded scrollbar with the Listemann Toolkit settings page.
