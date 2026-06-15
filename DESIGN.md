---
name: Visit Marche
description: Warm, place-rooted tourism destination site for Marche-en-Famenne
colors:
  cta-teal: "#487f89"
  cta-coral: "#fd8383"
  cta-green: "#16ba99"
  patrimony: "#aab7d8"
  walk: "#64966f"
  art: "#f5cc73"
  delicacy: "#efbfb1"
  party: "#efd7cd"
  home: "#e8dacb"
  pastel: "#e7dacb"
  campaign-cream: "#f4efe6"
  campaign-navy: "#1d2b3a"
  campaign-olive: "#3f4d2e"
  ink: "#212529"
  grey-dark: "#636061"
  grey-basic: "#808080"
  bg-lighter: "#ededec"
  border-soft: "#dee2e6"
  surface: "#ffffff"
typography:
  display:
    fontFamily: "Montserrat, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2rem, 5vw, 2.25rem)"
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "Montserrat, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 700
    lineHeight: 1.2
  title:
    fontFamily: "Montserrat, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.4
  body:
    fontFamily: "Montserrat, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
  label:
    fontFamily: "Montserrat, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.85rem"
    fontWeight: 700
    letterSpacing: "0.04em"
rounded:
  md: "6px"
  lg: "8px"
  xl: "12px"
  "2xl": "16px"
  full: "9999px"
spacing:
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "40px"
components:
  button-primary:
    backgroundColor: "{colors.cta-teal}"
    textColor: "{colors.surface}"
    rounded: "{rounded.full}"
    padding: "12px 24px"
  button-primary-hover:
    backgroundColor: "{colors.cta-coral}"
    textColor: "{colors.surface}"
  chip:
    backgroundColor: "{colors.cta-teal}"
    textColor: "{colors.cta-teal}"
    rounded: "{rounded.full}"
    padding: "4px 12px"
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.lg}"
    padding: "16px"
---

# Design System: Visit Marche

## 1. Overview

**Creative North Star: "The Warm Welcome"**

Visit Marche is the front door of a real place. The system behaves like a host opening that door: a soft, light ground, real photography of the town and the Famenne countryside, and a small set of warm accents that point the visitor toward their next step. It is unhurried and generous. Nothing shouts; the imagery and the place do the talking, and the interface stays out of the way.

Warmth here is earned, not decorated. It comes from photography, the regional palette, and human first/second-person copy ("et si vous viviez l'expérience complète ?"), never from gratuitous effects. The structure orbits five visitor intents (visiter, manger, dormir, bouger, s'inspirer), each carrying a soft signature hue so a returning visitor learns the color language without being told it.

This system explicitly rejects the generic SaaS/AI-template look (flat repeating card grids, gradient hero blobs, tracked-eyebrow scaffolding), the cold corporate/civic tone (bureaucratic gray, joyless forms), the cluttered OTA/booking-portal density (ad-heavy, pushy cross-sell), and loud gimmickry (neon, heavy animation, stacked pop-ups). It must also survive translation into fr/en/nl/de without breaking.

**Key Characteristics:**
- Light, warm ground with a compact set of coral/teal accents
- Real photography as the primary emotional carrier
- Five intent categories, each with a soft signature hue
- Soft, layered depth: gentle shadows and rounded corners
- Calm density; restraint is part of the brand
- Multilingual-resilient layouts (fr/en/nl/de)

## 2. Colors

A light, warm palette: a coral-and-teal accent pair for action, a band of soft pastel hues for the visitor intents, and warm-leaning neutrals for text and structure.

### Primary
- **Famenne Teal** (#487f89): The default action and focus color. Links, primary CTAs, focus rings, icon accents. The calm, trustworthy half of the accent pair.
- **Welcome Coral** (#fd8383): The warm accent. Primary-button hover, underline accents (`h-1 w-16 rounded-full`), the inset top-nav marker, highlight headings. Used to add warmth and signal interactivity, never as large fields.

### Secondary
- **Vivid Green** (#16ba99): A brighter green reserved for occasional positive/active emphasis. Use sparingly; it is louder than the rest of the palette.

### Tertiary
The intent-category hues. Each is a soft, desaturated tint used as a section/category signature, not as text:
- **Patrimony Blue** (#aab7d8): heritage / "visiter".
- **Walk Green** (#64966f): nature, walks, "bouger".
- **Art Gold** (#f5cc73): art / inspiration.
- **Delicacy** (#efbfb1), **Party** (#efd7cd), **Home** (#e8dacb), **Pastel** (#e7dacb): food, events, lodging and supporting warm tints.

### Neutral
- **Ink** (#212529): primary body and heading text.
- **Stone Grey** (#636061): secondary text, captions, muted labels.
- **Basic Grey** (#808080): tertiary/disabled text. Verify contrast before using on tinted grounds.
- **Lighter Surface** (#ededec): subtle section fills.
- **Soft Border** (#dee2e6): hairline dividers and card borders.
- **Surface** (#ffffff): default card and panel ground.

### Campaign Surface (the "Statues en Marche" modal and similar features)
- **Stone Cream** (#f4efe6): warm card ground for promotional features.
- **Deep Navy** (#1d2b3a): dark imagery panel and partner-logo chip.
- **Olive** (#3f4d2e): campaign headings on cream.

### Named Rules
**The Earned-Warmth Rule.** Warmth comes from photography, the accent pair, and copy, never from effects. No gradient heroes, no decorative glass, no neon.

**The Quiet-Accent Rule.** Coral and teal are accents, not fields. Keep the bright accents (coral, vivid green) to a small share of any screen; let the light ground and imagery carry the surface.

**The Contrast-Floor Rule.** The soft category hues and Basic Grey are decorative tints, not text colors on light grounds. Body text holds ≥4.5:1 (WCAG 2.1 AA); when a tint must carry a label, darken toward Ink rather than relying on gray.

## 3. Typography

**Display Font:** Montserrat (with ui-sans-serif, system-ui, sans-serif fallback)
**Body Font:** Montserrat
**Label Font:** Montserrat (semi-bold / bold weight)

**Character:** One geometric humanist sans, loaded as a variable font (100–900, with italics), carrying the whole system through weight contrast rather than a second face. Friendly and rounded enough to feel welcoming, structured enough to stay legible across four languages.

### Hierarchy
- **Display** (700, clamp(2rem, 5vw, 2.25rem), 1.1, -0.02em): Feature and campaign headings (e.g. the modal title). Tighten tracking modestly; never below -0.04em.
- **Headline** (700, 1.5rem, 1.2): Section headings.
- **Title** (600, 1.125rem, 1.4): Card titles, sub-section labels (`font-montserrat-semi-bold text-cta-dark`).
- **Body** (400, 1rem, 1.6): Reading text. Cap measure at 65–75ch.
- **Label** (700, 0.85rem, +0.04em, often uppercase): Category labels and badges. Uppercase only for short labels (≤4 words); never for sentences.

### Named Rules
**The One-Family Rule.** Montserrat carries everything. Express hierarchy through weight (400 / 600 / 700) and size, not a second typeface.

**The Translation-Headroom Rule.** Size and wrap headings so fr/en/nl/de strings never overflow or truncate. Test the longest language, not the shortest.

## 4. Elevation

Soft and layered. Surfaces sit on a light ground and are gently lifted with diffuse shadows; depth is welcoming, not dramatic. Cards, the modal, floating chips and the photo collage all read as softly raised rather than hard-edged. Corners are consistently rounded (8px on cards, full-pill on chips and buttons), which reinforces the soft, approachable feel.

### Shadow Vocabulary
- **Ambient card** (`box-shadow: shadow-md`): Default resting lift for cards and floating UI.
- **Raised / hover** (`box-shadow: shadow-lg`): Hover and emphasized cards; the photo collage and badges.
- **Overlay** (`box-shadow: shadow-2xl`): True overlays only: the campaign modal card.
- **Top-nav marker** (`box-shadow: 0 -3px 0 0 #fd8383 inset`): The signature coral underline on the top navigation.

### Named Rules
**The Soft-Lift Rule.** Shadows are diffuse and low-contrast. If a shadow looks like a hard 2014-app drop shadow (dark, tight blur), it is wrong; widen the blur and lighten it. Pair a soft shadow OR a hairline border, not both as decoration.

## 5. Components

### Buttons
- **Shape:** Full pill (`rounded-full`) for primary actions and icon buttons; 8px (`rounded-lg`) for wider/inline buttons.
- **Primary:** Famenne Teal (#487f89) ground, white text, generous padding (~12px 24px).
- **Hover / Focus:** Hover shifts toward Welcome Coral (#fd8383) or lifts opacity; focus shows a 2px coral focus ring with offset (`focus-visible:ring-2 ring-cta-light ring-offset-2`). Focus states are always visible (WCAG 2.1 AA).
- **Icon button:** 32px circle (`h-8 w-8 rounded-full`), Stone Grey/`caractere` ground, white glyph, hover to coral.

### Chips
- **Style:** Pill (`rounded-full`), translucent teal ground (`bg-cta-dark/10`), Famenne Teal text, compact padding (`px-3 py-1`, `text-sm font-medium`).
- **State:** Used as quiet category/metadata tags, not loud filters.

### Cards / Containers
- **Corner Style:** 8px (`rounded-lg`); features and modals up to 16px (`rounded-2xl`).
- **Background:** Surface white (#ffffff); campaign features on Stone Cream (#f4efe6).
- **Shadow Strategy:** Ambient `shadow-md` at rest; `shadow-lg` on hover (see Elevation).
- **Border:** Optional hairline Soft Border (#dee2e6 / slate-200); on hover, border shifts to Famenne Teal.
- **Internal Padding:** 16–24px (`p-4` to `p-6`), looser on large features.

### Navigation
- **Style:** Light top nav with the signature coral inset underline marker (`shadow-topNav`). Links in Ink/Stone Grey, hover to Famenne Teal.
- **States:** Hover/active carry the coral marker; focus shows the coral ring. Mobile collapses to a menu; keep labels translatable.

### Signature: Promotional Modal ("Statues en Marche")
A full-screen Alpine.js dialog on a Stone Cream card, two-column on desktop (content + imagery), auto-opening once per session. Deep Navy imagery panel with a soft photo collage, a teal/coral focus-ring close button, and the five intent categories rendered as soft-hued icon links. Partner logos sit in a Deep Navy chip. This is the reference for how campaigns should feel: warm, generous, photography-led, accessible (roles, labels, escape-to-close).

## 6. Do's and Don'ts

### Do:
- **Do** lead with real photography of Marche and the Famenne; let imagery carry the warmth.
- **Do** keep Famenne Teal (#487f89) as the default action/focus color and Welcome Coral (#fd8383) as the warm accent.
- **Do** hold body text to ≥4.5:1 contrast and large text to ≥3:1 (WCAG 2.1 AA); darken soft category tints toward Ink when they carry text.
- **Do** express type hierarchy with Montserrat weights (400/600/700), not a second font.
- **Do** keep depth soft: `shadow-md` at rest, `shadow-lg` on hover, rounded corners (8px cards, full-pill chips/buttons).
- **Do** give every surface one clear next step (explore a category, open an offer, plan a stay).
- **Do** size and wrap headings so fr/en/nl/de translations never overflow.

### Don't:
- **Don't** ship the generic SaaS/AI-template look: flat repeating card grids, gradient hero blobs, or a tracked uppercase eyebrow above every section.
- **Don't** drift into a cold corporate/civic tone: bureaucratic gray, joyless form-heavy layouts.
- **Don't** adopt cluttered OTA/booking-portal density: ad-heavy stacks, pushy cross-sell, competing CTAs.
- **Don't** go loud or gimmicky: neon, heavy gratuitous animation, stacked pop-ups, carnival energy.
- **Don't** use gradient text, decorative glassmorphism, or side-stripe (`border-left` > 1px) accents.
- **Don't** pair a 1px border with a wide soft drop shadow as decoration on the same element; pick one.
- **Don't** over-round: cards top out at 16px (`rounded-2xl`); full-pill is for chips and buttons only.
- **Don't** let the bright accents (coral, vivid green) flood the surface; they are accents, not fields.
