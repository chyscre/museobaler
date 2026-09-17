# Requirements Document

## Introduction

This feature adds two complementary exhibit identification methods to the Museo de Baler visitor web app:

1. **Live Camera / Image Search** — A visitor points their device camera at a physical exhibit and the app identifies it in real time using visual recognition, then navigates directly to the exhibit detail page. Recognition is seamless and automatic; no physical marker needs to be visible.

2. **Auto-Generated QR Codes (fallback)** — Every exhibit automatically has a unique QR code generated and stored by the system. These codes can be printed and placed discreetly (or not at all — they are also accessible via the admin panel). When a visitor scans a QR code, the app identifies the exhibit the same way as a visual scan. This fallback preserves accessibility for exhibits where physical QR placement would be aesthetically inappropriate.

Both methods funnel into the same exhibit detail flow that already exists in the app. The feature integrates with the existing `Exhibit`, `ExhibitImage`, and `Scan` models, and extends the visitor-facing `app.js` and the flat-PHP API layer in `public/api/`.

---

## Glossary

- **Visitor_App**: The vanilla-JS single-page application served from `public/visitor/`.
- **Image_Search_Service**: The client-side and server-side subsystem responsible for submitting a camera frame and returning a matching exhibit.
- **QR_Generator**: The server-side component that creates and stores a unique QR code URL for each exhibit.
- **QR_Scanner**: The existing HTML5-QRCode-based component in `app.js` that decodes QR codes from the camera feed.
- **Live_Camera_View**: The real-time camera preview shown to the visitor while the Image_Search_Service is active.
- **Exhibit_Detail_Screen**: The existing exhibit detail view (`showExhibitDetail`) rendered inside the Visitor_App.
- **Image_Search_API**: The endpoint at `public/api/image_search.php` that receives a camera frame and returns a matched exhibit.
- **QR_Code_URL**: A deep-link URL of the form `{base}/visitor/?scan={exhibit_code}` embedded inside each auto-generated QR code.
- **Admin_Panel**: The Laravel-based staff/admin interface.
- **Confidence_Score**: A numeric value (0.0–1.0) returned by the Image_Search_Service indicating how certain the match is.
- **Scan_Record**: A row in the `scans` table recording which visitor viewed which exhibit.

---

## Requirements

### Requirement 1: Live Camera Exhibit Identification

**User Story:** As a museum visitor, I want to point my device camera at an exhibit and have it automatically identified, so that I can access exhibit information without scanning a QR code or searching manually.

#### Acceptance Criteria

1. WHEN a visitor opens the scan screen and the camera is active, THE Live_Camera_View SHALL continuously sample frames at a maximum interval of 2 seconds and submit them to the Image_Search_Service for analysis.
2. WHEN the Image_Search_Service returns a match with a Confidence_Score of 0.75 or higher, THE Visitor_App SHALL navigate directly to the Exhibit_Detail_Screen for the matched exhibit without requiring any further visitor action.
3. WHEN the Image_Search_Service returns a match with a Confidence_Score below 0.75, THE Visitor_App SHALL display a low-confidence banner listing up to 3 candidate exhibit names and prompt the visitor to confirm the correct one.
4. WHEN the Image_Search_Service returns no match, THE Visitor_App SHALL display a "No exhibit recognized — try moving closer or improving lighting" message and resume sampling.
5. WHILE a frame is being submitted for analysis, THE Live_Camera_View SHALL display a non-blocking scanning indicator so the visitor knows recognition is in progress.
6. IF the Image_Search_API returns an HTTP error or times out after 8 seconds, THEN THE Visitor_App SHALL display a dismissible error toast and continue the live sampling loop.
7. THE Live_Camera_View SHALL request only the rear-facing camera by default and fall back to any available camera if a rear-facing camera is not available.
8. WHEN the visitor navigates away from the scan screen, THE Live_Camera_View SHALL stop frame sampling and release the camera immediately.

---

### Requirement 2: Auto-Generated QR Codes per Exhibit

**User Story:** As a museum administrator, I want every exhibit to automatically have a unique QR code, so that a fallback identification method is always available without manual configuration.

#### Acceptance Criteria

1. WHEN an exhibit is created or saved, THE QR_Generator SHALL produce a unique QR_Code_URL encoding the exhibit's `exhibit_code` and store it as a retrievable asset.
2. THE QR_Generator SHALL produce QR codes in SVG format at a minimum size of 256×256 pixels.
3. WHEN the Admin_Panel loads the exhibit list or exhibit detail page, THE Admin_Panel SHALL display a thumbnail of each exhibit's QR code and provide a download link.
4. THE QR_Code_URL embedded in each QR code SHALL follow the pattern `{origin}/visitor/?scan={exhibit_code}` so that the Visitor_App can handle it via the existing `mb_pending_scan` flow.
5. IF an exhibit's `exhibit_code` is updated, THEN THE QR_Generator SHALL regenerate the QR code for that exhibit on the next request, invalidating the previous code.
6. THE Admin_Panel SHALL provide a "Download All QR Codes" action that produces a ZIP archive containing one SVG file per active exhibit, named `{exhibit_code}.svg`.

---

### Requirement 3: QR Code Scanning (Fallback Path)

**User Story:** As a museum visitor, I want to scan a QR code placed near an exhibit to identify it, so that I can access exhibit information even when visual recognition is unavailable.

#### Acceptance Criteria

1. WHEN the QR_Scanner decodes a QR_Code_URL matching the pattern `{origin}/visitor/?scan={exhibit_code}`, THE Visitor_App SHALL treat it identically to a successful Image_Search_Service match and open the Exhibit_Detail_Screen.
2. WHEN a QR code is scanned that contains an unrecognised `exhibit_code`, THE Visitor_App SHALL display an error message "Exhibit not found. Please try again." and keep the scan screen open.
3. THE QR_Scanner SHALL operate simultaneously with the Live_Camera_View so that a single camera session can identify exhibits via either method without requiring the visitor to switch modes.

---

### Requirement 4: Scan Recording

**User Story:** As a museum administrator, I want all exhibit identifications — whether via image search or QR scan — to be recorded, so that I can track visitor engagement accurately.

#### Acceptance Criteria

1. WHEN an exhibit is successfully identified through the Image_Search_Service, THE Image_Search_API SHALL insert a Scan_Record linking the exhibit and the visitor (using `visitor_id` when available).
2. WHEN an exhibit is identified through the QR_Scanner, THE Visitor_App SHALL call the existing scan-logging endpoint to insert a Scan_Record, identical to the current QR scan flow.
3. THE Image_Search_API SHALL record a `scan_type` value of `'image'` on Scan_Records created by image search, and the existing QR flow SHALL record `'qr'`, so that the Admin_Panel can distinguish identification methods in analytics.
4. IF a visitor is not registered (no `visitor_id`), THEN THE Image_Search_API SHALL still insert the Scan_Record with a null `visitor_id` so that aggregate exhibit view counts remain accurate.

---

### Requirement 5: Image Search Backend

**User Story:** As a developer, I want a server-side image matching endpoint, so that the Visitor_App has a reliable, privacy-respecting way to identify exhibits from camera frames.

#### Acceptance Criteria

1. THE Image_Search_API SHALL accept HTTP POST requests at `public/api/image_search.php` with a multipart form field `frame` containing a JPEG image no larger than 1 MB.
2. WHEN a valid frame is received, THE Image_Search_API SHALL compare it against stored exhibit images using a perceptual hashing or feature-matching strategy and return the best match.
3. THE Image_Search_API SHALL return a JSON response containing `exhibit_code`, `confidence` (0.0–1.0), and `candidates` (an array of up to 3 objects each with `exhibit_code`, `name`, and `confidence`).
4. IF the submitted frame exceeds 1 MB, THEN THE Image_Search_API SHALL return HTTP 413 with `{"error": "frame_too_large"}`.
5. IF no exhibit images are on file or no match meets a minimum internal threshold of 0.40, THEN THE Image_Search_API SHALL return `{"exhibit_code": null, "confidence": 0, "candidates": []}`.
6. THE Image_Search_API SHALL apply the existing rate-limit helper (maximum 30 requests per minute per IP) to prevent abuse.
7. THE Image_Search_API SHALL NOT store the submitted camera frame on disk; it SHALL process it in memory only and discard it after the response is sent.

---

### Requirement 6: Exhibit Image Reference Data

**User Story:** As a museum administrator, I want the exhibits I photograph and upload to serve as the reference data for visual recognition, so that the image search feature uses accurate, current exhibit images.

#### Acceptance Criteria

1. THE Image_Search_Service SHALL use the primary exhibit image (`exhibits.image`) and all gallery images (`exhibit_images.filename`) for each active exhibit as reference data.
2. WHEN a new image is uploaded for an exhibit, THE Image_Search_API SHALL incorporate the new image into its matching index on the next request without requiring a server restart.
3. THE Image_Search_API SHALL skip exhibits with no associated images rather than producing false matches against empty data.
4. WHERE an exhibit has multiple images, THE Image_Search_API SHALL return the highest-confidence match across all images for that exhibit as the single result for that exhibit.

---

### Requirement 7: Visitor UX — Recognition States

**User Story:** As a museum visitor, I want clear visual feedback during recognition so I always know what the app is doing.

#### Acceptance Criteria

1. WHEN the Live_Camera_View is initializing the camera, THE Visitor_App SHALL display a "Starting camera…" placeholder and replace it with the live feed as soon as the camera stream is available.
2. WHILE the Image_Search_Service is waiting for an API response, THE Live_Camera_View SHALL show a pulsing border or overlay animation on the viewfinder.
3. WHEN a match is found with high confidence, THE Visitor_App SHALL briefly show a "Match found!" success flash (visible for 800 ms) before transitioning to the Exhibit_Detail_Screen.
4. WHEN the visitor dismisses the low-confidence candidate list, THE Visitor_App SHALL resume live sampling immediately.
5. THE Visitor_App SHALL display a "Camera not available" message with a fallback button to open a file picker for still-image search if camera access is denied.

