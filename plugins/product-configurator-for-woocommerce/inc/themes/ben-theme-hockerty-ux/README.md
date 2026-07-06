# Ben Theme Hockerty UX

A custom theme for the Product Configurator for WooCommerce plugin, inspired by the Hockerty shirt configurator UX.

## Features

### Mobile Version
- Clean layout with product preview centered
- Bottom navigation tabs (TISSU/STYLE/EXTRAS)
- Slide-in drawer for options selection
- Sticky footer with price and "Add to Cart" button
- Icon-based option selection with real-time preview updates

### Desktop Version
- Left sidebar with organized option categories
- Full product preview on the right
- Prominent "Add to Cart" button
- Quantity selector visible
- Reset configuration option

### Key Features
- **Responsive Design**: Seamless switching between mobile and desktop layouts
- **Icon-based Selections**: Visual icons for options like collars, cuffs, etc.
- **Live Preview**: Real-time product preview updates when options are selected
- **Color Swatches**: Support for color swatch display mode
- **Dropdown Mode**: Support for dropdown selection display
- **Customizer Integration**: Full WordPress Customizer support for colors and settings

## Installation

The theme is automatically registered when placed in the `inc/themes/ben-theme-hockerty-ux` folder.

## Customization

### Via WordPress Customizer
Navigate to **Appearance > Customize > Product Configurator** to access:
- Color Mode (Light/Dark)
- Primary colors
- Button colors
- Background colors
- Sidebar width
- Mobile navigation style

### CSS Variables
The theme uses CSS custom properties for easy customization:

```css
:root {
  --mkl_pc_primary: #F5841B;
  --mkl_pc_primary_hover: #E0740F;
  --mkl_pc_sidebar_width: 320px;
  --mkl_pc_footer_height: 80px;
  --mkl_pc_mobile_nav_height: 70px;
}
```

## File Structure

```
ben-theme-hockerty-ux/
├── index.php           # Security file
├── style.css           # Main stylesheet
├── theme.php           # PHP functions and hooks
├── ben-hockerty-ux.js  # JavaScript interactions
└── README.md           # This file
```

## Changelog

### 1.0.0
- Initial release
- Mobile and desktop responsive layouts
- Icon-based option selection
- Real-time preview updates
- WordPress Customizer integration
