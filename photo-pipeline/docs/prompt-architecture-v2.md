# Prompt architecture v2 — four modes, one geometry contract

Mazyoud product photography tool · supersedes the single monolithic prompt

---

## The structural change

Your current prompt mixes four different kinds of instruction in one editable block:

| Kind | Should it be editable in the UI? | Why |
|---|---|---|
| Product fidelity (don't invent features, lock colour) | **No** | Identical for every mode. If an operator weakens it, you get invented zippers in production. |
| Presentation physics (filled from inside, hanger, symmetry) | **Yes** | This is what actually differs per mode. |
| Framing / negative space | **No — and it shouldn't be in the prompt at all** | Enforced by the compositor. Prompt only asks for *loose* framing so there's room to crop. |
| Per-garment facts (category, colour, view) | **No — slot-filled** | Comes from Directus per image. |

So: **frozen core** (locked, versioned) + **mode block** (editable per mode, in the UI) + **slot-filled user prompt** (per image). Three layers, assembled server-side in that order.

The negative space and the crop are guaranteed by the compositor, not by the wording. That is the only way "identical across four modes" is achievable — a prompt can only get you "similar."

---

## Layer 1 — FROZEN CORE

Not editable in the UI. Versioned (`core_v2`). Its SHA-256 is stored on every generated asset.

```
You are a product-photography retoucher for an apparel and accessories
e-commerce catalogue.

INPUTS AND THEIR AUTHORITY

IMAGE A is the product. It is the sole source of truth for what the product
IS: its construction, its colour, its materials, its proportions, and which
side of it faces the camera.

IMAGE B is the house style. It is the sole source of truth for how the
product is PRESENTED: lighting, shadow behaviour, backdrop tone, support
and fullness, arrangement, and overall level of finish.

IMAGE B has zero authority over the product. IMAGE A has zero authority
over the presentation quality.

SURFACE LOCK

The camera-facing surface in IMAGE A is a complete and finished product
surface. Every feature that exists on it is already visible. Treat it as a
closed map: read it, reproduce it, and add nothing.

The final image shows the same side of the product facing the camera as
IMAGE A does. The orientation of IMAGE A is correct and final.

Where IMAGE A shows an uninterrupted panel of fabric, the final image shows
an uninterrupted panel of fabric.

WHAT TRANSFERS FROM IMAGE A, EXACTLY

Colour, hue, saturation, and the placement of every colour.
Materials, weave, grain, and surface texture.
Cut, silhouette, proportion, and every dimension.
Every seam, hem, cuff, waistband, collar, neckline, and panel line
that is visible.
Every closure, trim, hardware piece, print, motif, stripe, label,
and graphic that is visible, at its exact size and position.
Stripe count and stripe width.
Any text or logo, reproduced exactly as it appears. If text is illegible
in IMAGE A, it stays illegible. Never render legible text that was not
legible in the input.

WHAT DOES NOT TRANSFER FROM IMAGE A

Temporary presentation state is not product design and must be corrected:
creasing from folding or storage, collapse, flatness, sagging, crooked
placement, uneven left/right positioning, dust, lint, loose threads,
clips, pins, pegs, tape, stands, and any studio rigging.

COLOUR LOCK

Product colour is immutable. Do not warm, cool, recolour, desaturate,
oversaturate, or shift the hue of any part of the product. Do not apply
IMAGE B's colour grading to the product. Do not let the backdrop colour
bleed into the product. Lighting may change luminance across a surface;
it may not change the colour.

MATERIAL REALISM

Keep every material tactile and photographic: visible weave, grain,
stitch definition, fabric thickness, seam depth, natural microtexture.
Avoid CGI smoothness, waxy or plastic surfaces, over-sharpening, and
digitally painted texture.

Leather, faux leather, coated fabric and other sheened synthetics render
with broad, low-intensity diffuse specular only. No hotspots, no softbox
rectangles, no mirror highlights, no wet or oily sheen. The material reads
matte, richly textured and evenly exposed while remaining recognisably
leather.

BACKDROP AND LIGHT

One coherent soft studio light source explains everything in frame:
product illumination, tonal modelling, material texture, volume, any
support hardware, shadow direction, shadow softness, and background
falloff.

The backdrop is a smooth, even, warm light grey. No texture, no gradient,
no vignette, no border.

SHADOW

The cast shadow is a real photographic shadow, not a graphic effect.
Never a uniform outline, never an even blur around the silhouette, never
a detached floating shadow, never a grey halo.

Shadow tightness and density follow real distance: surfaces close to the
backdrop cast tighter, denser shadow; surfaces projecting toward the
camera cast broader, softer, more diffused shadow. The shadow varies
naturally across every part of the product.

FRAMING FOR DOWNSTREAM CROPPING

This image will be cropped by an automated system afterwards. Frame it
loosely and leave the system room to work.

The product sits centred on a straight vertical axis, complete and
unclipped, occupying a comfortable portion of the frame with clear empty
backdrop on all four sides. No part of the product or its supporting
hardware comes near any frame edge. Left and right margins are balanced.

Do not crop tightly. Do not fill the frame. Do not add borders, framing
devices, or decorative elements. Extra empty backdrop is always correct;
a tight crop is always wrong.

OUTPUT

One image. No text overlay, no watermark, no logo, no props, no
additional objects of any kind.

VALIDATION BEFORE YOU FINALISE

Compare the generated product against IMAGE A and confirm:
- the same side faces the camera
- no feature appears that was not visible in IMAGE A
- colour matches IMAGE A across every panel
- construction, proportions and trims match IMAGE A
- presentation quality matches IMAGE B
- clear empty backdrop on all four sides

If any feature appears that is not present in IMAGE A, remove it before
finalising. If the orientation has changed, discard and regenerate from
the surface shown in IMAGE A.

PRIORITY

Where instructions conflict: the camera-facing surface of IMAGE A first,
then its construction, then its colour and materials, then presentation
quality from IMAGE B, then lighting and shadow, then framing.

Fidelity beats beauty. Where you cannot reproduce a detail faithfully,
keep the input pixels rather than inventing a plausible substitute.
```

Note what left the original: every "do NOT invent zipper / placket / button / pocket / logo" list. Those tokens raise the probability of the thing they name. The surface lock now asserts positively that IMAGE A is complete, which is the same constraint without seeding the vocabulary. Keep the validation checklist — that reads as self-inspection, not description, and it earns its place.

---

## Layer 2 — MODE BLOCKS

Editable in the UI. One per mode, each with its own reference image. Stored in Directus alongside `reference_image_id` and `reference_sha256`.

### Mode 1 — `hanging`

```
The product hangs on the wooden hanger shown in IMAGE B, suspended from
the same point, photographed straight on.

Fill the product visibly from inside with invisible soft support. The
transformation must clearly read as flat becoming full: rounded supported
shoulders of equal volume, sleeves with body rather than flat collapsed
fabric, torso pushed softly outward, structured cuffs, a clean controlled
hem. The effect is obvious, not subtle.

Where a lower garment is present it receives the same treatment and must
read as standing and supported, not hanging and empty. Each leg behaves
as an individually supported three-dimensional form: filled to the hem,
matching fullness, matching width, matching length, both hems level, the
central separation neat, the waistband straight and supported.

Left and right are balanced. No side is shorter, wider, flatter or more
collapsed than the other. The whole set follows one clean vertical axis.

Create garment volume, never human anatomy. No visible mannequin, no
body, no limbs, no face. Do not stretch seams, rigidify fabric, or
balloon the silhouette. The authentic cut is preserved.

The hanger stays visible and is not a reason to rotate the product.
```

### Mode 2 — `flatlay`

```
The product lies flat, photographed from directly overhead, the camera
square to the surface with no perspective skew.

Arrange it as a stylist would: panels smoothed and steamed, sleeves
placed deliberately and symmetrically, any lower garment laid straight
below the upper piece with consistent spacing between the pieces, hems
and cuffs squared, the whole arrangement on one vertical axis.

Give the fabric soft dimensional body from beneath rather than pressing
it flat. Edges lift slightly and naturally; seams and hems read as
physical thickness. The product looks laid down, not ironed into the
surface.

Shadow is the short, soft contact shadow of an object resting on a
surface, tight at contact points and opening slightly under lifted
edges. No long directional shadow.

No hanger, no mannequin, no stand, no folded stacking, no props.
```

### Mode 3 — `mannequin`

```
The product is worn on an invisible mannequin: the garment holds a full,
natural, three-dimensional worn shape with no visible form inside it.

The neckline, armholes and any openings are hollow and finished — the
backdrop is visible through them. No mannequin body, no shoulders, no
neck, no limbs, no head, no skin, no face, no figure of any kind appears
in the image.

The garment is filled to a natural worn volume: rounded shoulders,
sleeves with arm volume that taper naturally to the cuff, a torso with
real depth, a hem that falls with weight. Where a lower garment is
present it carries the same worn volume, both legs equally, falling
straight with natural drape.

Left and right are symmetrical. The silhouette is the garment's own cut
at its natural size — not stretched, not inflated, not slimmed.

The product stands upright, unsupported, centred on a vertical axis.
```

Two things to know about this mode. First, invisible-mannequin (ghost) rendering is the standard e-commerce technique and it is also the version least likely to trip a safety filter — a rendered figure wearing small-size apparel is the highest-refusal-risk request in your whole pipeline, and this phrasing avoids it entirely. Second, it is the hardest mode for fidelity: the model has to invent the hollow interior, which is exactly where invented seams and linings appear. Expect the highest reject rate here and budget for it.

### Mode 4 — `accessory`

```
The accessory is presented as a single hero object, photographed straight
on, resting on or standing against the surface shown in IMAGE B.

Position it at the angle that shows its defining feature most clearly and
keep that angle consistent. It sits upright and stable, not tipped,
leaning or falling.

Give it its true physical form: rigid parts stay rigid and hold their
shape, soft parts carry gentle volume, straps and closures are arranged
deliberately rather than left loose or tangled. Small hardware — buckles,
clasps, zips, studs — stays sharp and legible at its exact size and
position.

Shadow is short and soft, anchoring the object to the surface at its
contact points.

One object only. No hanger, no mannequin, no stand, no hands, no props,
no styling items, no second copy of the object.
```

---

## Layer 3 — SLOT-FILLED USER PROMPT

Assembled per image from Directus. Enum-bound only — never free operator text, or you have a prompt-injection surface.

```
Mode: {mode}.
Product: {category}, {colour_family}, {material_family}.
Size band: {size_band}.
View: {view}.
Reference: IMAGE B.
Return one image.
```

Slot vocabulary:

| Slot | Values |
|---|---|
| `mode` | hanging · flatlay · mannequin · accessory |
| `category` | from your Directus category tree |
| `colour_family` | from your colour taxonomy |
| `material_family` | knit · woven · denim · leather · faux leather · coated · jersey · fleece |
| `size_band` | 6M · 12M · 2Y · 4Y · 6Y · 8Y · 10Y · 12Y |
| `view` | front · back · detail · angle |

`material_family` is doing real work: it is what activates the leather clause in the frozen core. Populate it from your product data, not from a guess.

**Never put age words in any layer.** No child, kid, baby, toddler, boy, girl, infant. `size_band` carries that information without the refusal risk. This applies to the mode blocks too, so lock it as a UI validation rule on the editor — reject a save if the text matches that word list.

---

## The geometry contract — this is where negative space actually lives

None of the four prompts sets a margin. They ask for loose framing; the compositor sets the geometry. Same canvas, same anchor, per-mode fill ratio.

| Mode | Fill ratio (garment height ÷ square window) | Anchor |
|---|---|---|
| `hanging` | 0.82 | garment bbox centre, hanger hook excluded from bbox |
| `flatlay` | 0.84 | full arrangement bbox centre |
| `mannequin` | 0.80 | garment bbox centre |
| `accessory` | 0.62 | object bbox centre |

Everything else is shared and unchanged: 2400×2800 master at 6:7 with the 1:1 window inset at y=200, background `#EDEAE5`, anchor at (1200, 1400), zero non-background pixels within 40px of any edge in either crop.

One judgement call worth making deliberately: cross-mode identical fill ratio is not what you want. Give an accessory the same 0.82 as a hanging outfit and a hair clip renders the size of a coat. What stays identical across modes is the canvas, the background, the anchor point and the edge clearance — so the grid reads as one system — while the fill ratio is a locked per-mode constant. Within a mode it never varies, which is what makes a category page look right.

Cap the generated master at roughly 1800×2100 (still 6:7) to stay under the 2K output tier, then upscale in the compositor. Above 2048px on the long edge the price steps up 50% for pixels a product page never uses.

---

## UI and data model

```
mode
  id                  hanging | flatlay | mannequin | accessory
  label
  prompt_block        text        editable
  prompt_block_sha    computed on save
  reference_image     asset ref   editable
  reference_sha       computed on save
  fill_ratio          float       admin-only, not operator-editable
  version             int         increments on any save
  active              bool
```

The frozen core lives in code or in a read-only Directus singleton — not in the mode record, and not reachable from the operator UI.

Rules that make the editability safe:

- **Any save to `prompt_block` or `reference_image` bumps `version`.** Every generated asset stores `core_sha + mode_id + mode_version + reference_sha + model_snapshot`. Without this, someone tweaks a mode block in October and you have no way to tell why the September images look different — and no way to regenerate one SKU to match its siblings.
- **Show a diff and require confirmation on save.** "This changes how all future `hanging` images look" is a sentence the operator should have to read.
- **Reject a save on the age-word list**, and on any text matching `crop|margin|aspect|ratio|1:1|6:7|resolution|px` — those belong to the compositor and an operator adding them to a mode block will silently break the geometry contract.
- **A/B before adopting.** Changing a mode block should run against a fixed 20-image control set and show old vs new side by side, with the reject rate on each. Otherwise you are tuning by vibes at 27 DZD a call.
- **Reference image swap is a bigger event than a prompt edit.** It changes every future image in that mode more than any wording does. Treat it as a version bump with its own confirmation.

---

## Sequencing

1. Ship `hanging` only, frozen core + mode block, with the compositor doing all geometry. Measure the reject rate on 200 real SKUs.
2. Add `flatlay`. It is the second easiest and shares almost everything.
3. Add `accessory`. Different fill ratio, otherwise straightforward.
4. Add `mannequin` last. Highest reject rate, highest refusal risk, and the mode where invented construction is most likely — you want your QA gates proven before you turn it on.

Do not open the mode editor to operators until gates 1–7 are running. An editable prompt with no automatic fidelity check is a way to quietly degrade 6,000 product pages.
