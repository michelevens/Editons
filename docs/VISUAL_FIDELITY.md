# Original Mockup — Visual Fidelity Contract

The user-approved source of truth is the ORIGINAL uploaded PNG named `Publishing Studio_ Manuscript Editing Dashboard.png` (1536 × 1024). An earlier recreated SVG is NOT the approved source and must not be used for design approval.

## Non-negotiable acceptance
- Recreate the original screenshot as closely as technically possible at viewport 1536 × 1024 with deviceScaleFactor=1.
- Keep the same top header and all eight navigation tabs, dark left book sidebar, manuscript tree, center editor, right AI editor, and the four bottom cards.
- Match position, width, height, padding, alignment, dividers, shadows, radius, typography, iconography, highlighted phrases, selected states, badges, and illustrations.
- Restore the FLOATING AI Suggestion card with Apply / Dismiss actions positioned over the center/right editor transition exactly as in reference.
- Restore the real visual appearance of the open-book spread and mountain-cover illustration (the current simplified CSS approximations are insufficient).
- Maintain the exact example manuscript, navigation labels, editorial overview metrics, and research library sample content shown in the source.
- Do not replace visual sections with unrelated stock imagery or generic dashboards.
- Preserve functional editing, chapter selection, saving, and UI interactivity where compatible with visual fidelity.

## Verification requirements
1. Use the actual approved PNG as a reference image in the development workspace; ensure it is available locally or explicitly request the original file. Do not silently substitute the earlier SVG.
2. Start the Vite application and resolve build/type errors.
3. Capture a Playwright browser screenshot at 1536 × 1024 and deviceScaleFactor=1.
4. Compare original and rendered images side-by-side and calculate an image-difference report (MAE/SSIM or suitable perceptual diff). Review obvious typography and geometry differences manually.
5. Iterate corrections on the rendered UI, recapture, and compare until visually acceptable.
6. Verify responsive behavior separately at tablet and mobile viewports without compromising desktop fidelity.
7. Attach the before/after screenshots and diff metrics to PR #1.
8. Do not merge or assert pixel-perfect completion before the screenshot verification succeeds.

## Current known gaps
- Original PNG has not yet been committed into the repository.
- Floating AI suggestion card is missing.
- Book spread and book cover are simplified CSS illustrations.
- Pixel-level rendered screenshot verification has not yet been performed.
- Sample dashboard indicators are static, not connected to AI.

## Approval gate
PR #1 must remain unmerged until the user reviews a browser screenshot and approves its resemblance to the original PNG.
