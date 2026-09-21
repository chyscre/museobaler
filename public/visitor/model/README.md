# Recognition model

The three files here are what the visitor app loads to recognise exhibits
through the camera:

- `model.json`
- `weights.bin`
- `metadata.json`  ← contains the class names (= exhibit codes, plus `Background`)

**They are written by the admin panel, not by hand.** Go to
*Recognition* in the admin sidebar: take photos of each exhibit (on a phone,
via *Manage photos*), take background photos, press *Train*. The browser
trains the model and saves it into this folder. See
`App\Services\Recognition` and `RecognitionController`.

If the folder is empty or the files are unreadable, the visitor app falls
back to the server-side photo matcher (`POST /api/v1/recognition`, `App\Services\ImageSearch`), which
uses the ordinary exhibit gallery pictures and is much less accurate. The QR
codes always work regardless.

## Manual export (only if the panel cannot be used)

The panel produces the same files as Teachable Machine's TensorFlow.js
export, so one made at https://teachablemachine.withgoogle.com/train/image
can be dropped here as a stopgap. Class names must be the exhibit codes
exactly as shown in the admin panel (e.g. `EXH-005`), plus one class named
`Background`. Rename `model.weights.bin` to `weights.bin` if the export used
that name, or edit `weightsManifest[0].paths` in `model.json` to match.
