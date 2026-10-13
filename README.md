# Listemann Addon

Custom Elementor widgets for [listemann.com](https://listemann.com/). The first widget, **Continuous Slider**, recreates the full-width "Einsatzgebiete" image strip from the homepage as a native Elementor widget. Before, that strip was a theme shortcode rendered with slick.

- Requires WordPress 6.5+, PHP 7.4+ and Elementor 3.24+ (tested with Elementor 4.3.5 and Elementor Pro 4.3.1). Elementor Pro is not required.
- Uses the classic widget API (`\Elementor\Widget_Base`), so the widgets work with and without the Elementor V4 / atomic editor experiments.
- No build step, no jQuery in the slider engine, no external libraries.

## Installation

1. Create the installable zip from the repository (development files are excluded via `.gitattributes`):

   ```sh
   git archive --format=zip --prefix=listemann-addon/ -o listemann-addon.zip HEAD
   ```

2. In WordPress go to **Plugins → Add New → Upload Plugin**, upload the zip and activate it. Alternatively copy the folder to `wp-content/plugins/listemann-addon/`.

If Elementor is missing, inactive or older than 3.24, the plugin stays idle and shows an admin notice with an install, activate or update button.

## Continuous Slider

Find it in the Elementor panel under **Listemann → Continuous Slider** (search: *slider*, *carousel*, *marquee*).

### Recreating the homepage strip

1. Add a container (or section) and set **Content Width → Full Width** with **Padding 0** (legacy sections: *Stretch Section* on, *Columns Gap → No Gap*, column padding 0). The slider always fills its parent, so the parent decides how wide it is.
2. Add the Heading widget "Einsatzgebiete" above it as a separate widget, as on the original page.
3. Drop the **Continuous Slider** into the container. The defaults match the original: 4 slides (Luft- & Raumfahrt, Energie- & Umwelttechnik, Medizintechnik, Formen- & Werkzeugbau), 7 per view, 15 px gap, 550 px high (300 px on tablet and mobile), a 35 % black overlay that turns 75 % red (#dd191a) on hover, white titles bottom-left. On desktop and tablet the strip glides left continuously, one slide every 6 s. On mobile it steps one slide every 3.5 s and can be swiped.
4. Pick the image and link for each slide. You don't need to duplicate slides: the script clones them as often as the screen width requires.

### Settings

| Section | Control | Default | Notes |
| --- | --- | --- | --- |
| Content › Slides | Slides (image, title, link) | 4 slides | Image, title and link support dynamic tags. |
| | Image Resolution | Large | Registered sizes get `srcset`. |
| | Title HTML Tag | `span` | `span`, `div`, `p`, `h2`–`h6`. |
| Content › Slider | Slides per View ⧉ | 7 / laptop 5 / tablet 4 / mobile 2 | Decimals let the next slide peek in. |
| | Gap ⧉ | 15 px | |
| | Height ⧉ | 550 px / tablet 300 px / mobile 300 px | px, vh, em, rem. |
| | Motion ⧉ | Continuous / mobile Step | Continuous glide, Step, Static. |
| | Direction | Left | Applies to continuous and step motion. |
| | Continuous: Seconds per Slide | 6 | Time one slide plus gap takes to pass. |
| | Continuous: Pause on Hover / Allow Drag | off / off | Pausing eases out and back in. |
| | Step: Interval / Transition | 3500 ms / 300 ms | |
| | Step: Pause on Hover / Allow Drag | on / on | |
| | Static: Allow Drag | off | |
| | Accessible Label | Einsatzgebiete | `aria-label` of the carousel region. |
| Style › Slides | Border Radius ⧉, Image Position ⧉, CSS Filters (normal / hover) | –, center | |
| Style › Overlay | Color / Opacity (normal, hover), Transition Duration | #000 35 %, #dd191a 75 %, 300 ms | Hover and keyboard focus apply to the whole card. |
| Style › Title | Color (normal, hover), Typography, Text Shadow, Padding ⧉, Vertical Position ⧉, Alignment ⧉ | #fff, 1.3/2.2/4.2 vw, line height 1.7/2.8/6.5 vw, 0.5 px spacing, padding 0 15 15 20 px, bottom | |

⧉ = responsive control.

### Breakpoints

Elementor only enables the **Mobile (767 px)** and **Tablet (1024 px)** breakpoints by default. The original slick slider also changed at 1300 px (5 slides) and 1100 px (4 slides). To get those steps, enable the **Laptop** breakpoint (and, if needed, **Tablet Extra**) in *Site Settings → Layout → Breakpoints*, e.g. set Laptop to 1300 px. The widget already ships a laptop default of 5 slides, which applies as soon as that breakpoint is active. All layout values are CSS custom properties written by Elementor, so custom breakpoints work without code changes.

### Behaviour and accessibility

- **Rendering.** Every slide is rendered once by the server. Without JavaScript the first slides show statically, with no scrollbar.
- **Clones.** The script adds clones so the strip covers the viewport plus one full set. Clones are hidden from screen readers (`aria-hidden`) and removed from the tab order (`tabindex="-1"`). They are not `inert`: inert content ignores clicks and hover, and clones fill most of the visible strip.
- **Pausing.** Motion pauses while the slider is off-screen or the browser tab is hidden.
- **Reduced motion.** Visitors with `prefers-reduced-motion: reduce` get no autoplay. They can drag the strip instead, and keyboard focus still brings slides into view.
- **Keyboard.** When a slide link receives keyboard focus, the slider stops and moves that slide fully into view. It resumes when focus leaves the slider.
- **Dragging.** A press that moves more than 6 px counts as a drag and does not open the link. Native image and link drag ghosts are disabled.
- **Image alt text.** Slides with a visible title use an empty `alt` on the image, because the title already names the link. Slides without a title use the image's alt text from the media library.

### JavaScript API

The engine is available as `window.ListemannContinuousSlider` and also runs without Elementor:

```js
const slider = new ListemannContinuousSlider( document.querySelector( '.lst-cslider' ), {
	continuous: { speed: 4 }, // overrides data-lst-options
} );
slider.refresh(); // after changing the slider's CSS
slider.destroy(); // removes clones, listeners and observers
ListemannContinuousSlider.initAll(); // all .lst-cslider without an instance
```

## Development

```
listemann-addon.php                    Plugin header, constants, requirement checks, autoloader
includes/class-plugin.php              Bootstrap: Elementor checks, category, assets, widget list
includes/widgets/class-*.php           One file per widget
assets/css/*.css, assets/js/*.js       Front-end assets (registered, enqueued per widget)
languages/                             Translations (text domain listemann-addon)
```

### Adding a widget

1. Create `includes/widgets/class-my-widget.php` with the class `Listemann_Addon\Widgets\My_Widget` extending `\Elementor\Widget_Base`. The autoloader maps `My_Widget` to `class-my-widget.php`. Return `\Listemann_Addon\Plugin::CATEGORY` from `get_categories()`.
2. Add `Widgets\My_Widget::class` to `Plugin::WIDGETS` in `includes/class-plugin.php`.
3. If the widget needs assets, add them to `Plugin::SCRIPTS` / `Plugin::STYLES` (handle → path and dependencies). Return the handles from the widget's `get_script_depends()` / `get_style_depends()`, so Elementor only loads them on pages that use the widget.

Conventions used by the slider, worth keeping for new widgets:

- **Inner wrapper.** `has_widget_inner_wrapper()` returns `false` only when Elementor's optimized markup is active, because the Advanced tab relies on the wrapper otherwise. Write CSS that works both with and without `.elementor-widget-container`.
- **Values read in `render()`.** Controls with `selectors` are not available in `render()` on the front end (Elementor's optimized control loading). Values the PHP or JS needs go into plain controls or `data-*` attributes. Purely visual values go into CSS custom properties.
- **Front-end handlers.** Front-end JS attaches with `elementorFrontend.elementsHandler.attachHandler( '<widget-name>', Handler )` on the `elementor/frontend/init` event. The handler extends `elementorModules.frontend.handlers.Base`. Don't use class fields: `onInit()` runs inside the base constructor.

`SCRIPT_DEBUG` set to `true` appends the file modification time to asset URLs, which busts browser caches while developing.
