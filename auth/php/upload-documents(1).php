<?php
session_start();
require_once '../../shared/config/db pdo.php';

// Get token from URL
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

// Error and success messages
$error = '';
$success = '';
$tokenData = null;

if (empty($token)) {
    die('Invalid or missing upload link. Please use the link provided in your email.');
}

// Validate token (PDO, aligned to museek.sql)
$db = $pdo; // Use the same connection as gallery.php
$stmt = $db->prepare(
    "SELECT 
        dut.token, dut.expires_at, dut.is_used, dut.registration_id,
        sr.registration_id AS registration_id,
        sr.business_name, sr.owner_name, sr.owner_email, sr.registration_status
     FROM document_upload_tokens dut
     JOIN studio_registrations sr ON dut.registration_id = sr.registration_id
     WHERE dut.token = ? AND dut.is_used = 0"
);
$stmt->execute([$token]);
$tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenData) {
    die('Invalid or expired upload link. Please contact support.');
}

// Check if token expired
if (strtotime($tokenData['expires_at']) < time()) {
    die('This upload link has expired. Please contact support for a new link.');
}

// Check if already processed per registration_status
if (in_array(strtolower($tokenData['registration_status'] ?? ''), ['approved','rejected'])) {
    die('This registration has already been processed.');
}

// Get existing documents (PDO)
$docStmt = $db->prepare(
    "SELECT * FROM documents 
     WHERE registration_id = ? 
     ORDER BY document_type, uploaded_at DESC"
);
$docStmt->execute([$tokenData['registration_id']]);
$existingDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

// Get existing gallery photos if studio exists
$existingGallery = [];
$studioStmt = $db->prepare("SELECT studio_id FROM studio_registrations WHERE registration_id = ?");
$studioStmt->execute([$tokenData['registration_id']]);
$studioRow = $studioStmt->fetch(PDO::FETCH_ASSOC);
if ($studioRow && !empty($studioRow['studio_id'])) {
    $galleryStmt = $db->prepare(
        "SELECT * FROM studio_gallery 
         WHERE StudioID = ?
         ORDER BY sort_order ASC, uploaded_at DESC"
    );
    $galleryStmt->execute([$studioRow['studio_id']]);
    $existingGallery = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get latest payment (PDO, schema-aligned)
// Check workflow completion status (documents only)
$hasDocuments = count($existingDocs) >= 3; // Minimum 3 documents recommended
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Your Studio Registration - Museek</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 40px 0; }
        .upload-container { max-width: 900px; margin: 0 auto; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px 15px 0 0 !important; padding: 25px; }
        .progress-steps { display: flex; justify-content: space-between; margin: 30px 0; }
        .step { flex: 1; text-align: center; position: relative; }
        .step::after { content: ''; position: absolute; top: 20px; left: 50%; width: 100%; height: 2px; background: #dee2e6; z-index: -1; }
        .step:last-child::after { display: none; }
        .step-circle { width: 40px; height: 40px; border-radius: 50%; background: #dee2e6; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 10px; }
        .step.active .step-circle { background: #667eea; color: white; }
        .step.completed .step-circle { background: #28a745; color: white; }
        .upload-zone { border: 2px dashed #667eea; border-radius: 10px; padding: 40px; text-align: center; margin: 20px 0; cursor: pointer; transition: all 0.3s; }
        .upload-zone:hover { background: #f8f9fa; border-color: #764ba2; }
        .file-list { max-height: 300px; overflow-y: auto; }
        .file-item { padding: 15px; border: 1px solid #dee2e6; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .gcash-info { background: #e7f3ff; border-left: 4px solid #0056b3; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .btn-submit { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; padding: 12px 40px; }
    </style>
<?php // keep PHP open for secure output in header ?></head>
<body>
    <div class="upload-container">
        <div class="card">
            <div class="card-header">
                <h2 class="mb-0"><i class="bi bi-building"></i> Complete Your Studio Registration</h2>
                <p class="mb-0 mt-2">Welcome, <?= htmlspecialchars($tokenData['owner_name']) ?>!</p>
            </div>
            <div class="card-body p-4">
                <!-- Progress: Documents Only -->
                <div class="progress-steps">
                    <div class="step <?= $hasDocuments ? 'completed' : 'active' ?>">
                        <div class="step-circle"><i class="bi bi-file-earmark-text"></i></div>
                        <small>Upload Documents</small>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Review and Email Confirmation Notice -->
                <div class="alert alert-info d-flex align-items-center" role="alert">
                    <i class="bi bi-envelope-check me-2"></i>
                    <div>
                        Please wait until we confirm your documents. We will send a confirmation email to 
                        <strong><?= htmlspecialchars($tokenData['owner_email']) ?></strong> once review is complete.
                    </div>
                </div>

                <!-- Step 1: Document Upload -->
                <div class="mb-5">
                    <h4><i class="bi bi-file-earmark-arrow-up"></i> Step 1: Upload Required Documents</h4>
                    <p class="text-muted">Please upload the following documents to verify your studio:</p>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="alert alert-info">
                                <strong>Required:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Business Permit</li>
                                    <li>DTI Registration</li>
                                    <li>BIR Certificate</li>
                                    <li>Mayor's Permit</li>
                                    <li>Valid ID (Owner)</li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="d-flex justify-content-end mb-2">
                                <button class="btn btn-secondary" id="addUploadRowBtn" title="Add another document" style="padding:10px 14px;"><i class="bi bi-plus"></i></button>
                            </div>
                            <div id="uploadRows"></div>
                            
                            <!-- Validation Warnings Area -->
                            <div id="validationWarnings" class="alert alert-warning" style="display:none; margin-top: 15px;">
                                <strong><i class="bi bi-exclamation-triangle"></i> Validation Issues:</strong>
                                <ul id="validationList" style="margin-bottom: 0; margin-top: 8px;"></ul>
                            </div>
                            
                            <div class="text-center mt-3">
                                <button class="btn btn-primary" id="uploadAllBtn"><i class="bi bi-upload"></i> Upload Documents</button>
                                <div id="uploadStatus" style="margin-top: 10px; font-size: 14px;"></div>
                            </div>
                            <p class="text-muted mt-3">Accepted: JPG, PNG, PDF (Max 5MB)</p>
                            <input type="hidden" id="tokenField" value="<?= htmlspecialchars($token) ?>">
                            <input type="hidden" id="registrationField" value="<?= $tokenData['registration_id'] ?>">
                        </div>
                    </div>

                    <!-- Uploaded Documents List -->
                    <?php if (!empty($existingDocs)): ?>
                        <h5 class="mt-4">Uploaded Documents (<?= count($existingDocs) ?>)</h5>
                        <div class="file-list">
                            <?php foreach ($existingDocs as $doc): ?>
                                <div class="file-item">
                                    <div>
                                        <i class="bi bi-file-earmark-check text-success"></i>
                                        <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $doc['document_type']))) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($doc['file_name']) ?></small>
                                        <small class="text-muted">• Uploaded: <?= date('M d, Y H:i', strtotime($doc['uploaded_at'])) ?></small>
                                    </div>
                                    <span class="badge bg-<?= ($doc['verification_status'] ?? '') === 'valid' ? 'success' : 'warning' ?>">
                                        <?= ucfirst($doc['verification_status'] ?? 'pending') ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Step 2: Studio Gallery Photos -->
                <div class="mb-5">
                    <h4><i class="bi bi-images"></i> Step 2: Upload Studio Photos (Optional)</h4>
                    <p class="text-muted">Add 3 or more photos showcasing your studio space, facilities, and equipment.</p>
                    
                    <?php if (!$studioRow || empty($studioRow['studio_id'])): ?>
                        <div class="alert alert-warning" role="alert">
                            <i class="bi bi-info-circle"></i> 
                            <strong>Note:</strong> Gallery photos will be available after your registration is approved. 
                            For now, please focus on uploading your verification documents above.
                        </div>
                    <?php else: ?>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="upload-zone" id="galleryDropZone">
                                <i class="bi bi-cloud-upload" style="font-size: 48px; color: #667eea;"></i>
                                <h5 class="mt-3">Drag & Drop Photos Here</h5>
                                <p class="text-muted">or click to browse</p>
                                <input type="file" id="galleryInput" accept="image/jpeg,image/jpg,image/png" multiple style="display: none;">
                                <button type="button" class="btn btn-outline-primary mt-2" onclick="document.getElementById('galleryInput').click()">
                                    <i class="bi bi-folder2-open"></i> Select Photos
                                </button>
                            </div>
                            
                            <!-- Gallery Preview Area (Before Upload) -->
                            <div id="galleryPreview" style="display: none; margin-top: 20px;">
                                <h6>Photos Ready to Upload:</h6>
                                <div id="galleryThumbnails" class="row g-3"></div>
                                <div class="text-center mt-3">
                                    <button class="btn btn-success" id="uploadGalleryBtn">
                                        <i class="bi bi-upload"></i> Upload Gallery Photos
                                    </button>
                                    <div id="galleryStatus" style="margin-top: 10px; font-size: 14px;"></div>
                                </div>
                            </div>
                            
                            <p class="text-muted mt-3 mb-0">
                                <i class="bi bi-info-circle"></i> Accepted: JPG, PNG only (Max 5MB per photo)
                            </p>
                        </div>
                    </div>
                    
                    <!-- Uploaded Gallery Photos -->
                    <?php if (!empty($existingGallery)): ?>
                        <div class="mt-4">
                            <h5>Uploaded Gallery Photos (<?= count($existingGallery) ?>)</h5>
                            <div class="row g-3 mt-2">
                                <?php foreach ($existingGallery as $photo): ?>
                                    <div class="col-md-3 col-sm-4 col-6">
                                        <div class="card h-100" style="border: 2px solid #28a745;">
                                            <div style="position: relative;">
                                                <img src="/<?= htmlspecialchars($photo['file_path']) ?>" 
                                                     class="card-img-top" 
                                                     style="height: 180px; object-fit: cover; cursor: pointer;"
                                                     alt="Studio photo"
                                                     onclick="window.open('/<?= htmlspecialchars($photo['file_path']) ?>', '_blank')">
                                                <span style="position: absolute; top: 8px; right: 8px; background: rgba(40, 167, 69, 0.9); color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                                                    <i class="bi bi-check-circle-fill"></i> Uploaded
                                                </span>
                                            </div>
                                            <div class="card-body p-2">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-calendar3"></i> <?= date('M d, Y', strtotime($photo['uploaded_at'])) ?>
                                                        </small>
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-hash"></i> Order: <?= $photo['sort_order'] ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php endif; // End studio check ?>
                </div>

                <!-- Note: Payment is handled on a separate page/process -->
            </div>
            <div class="card-footer text-center text-muted">
                <small>Need help? Contact us at support@museek.com</small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function(){
            const addRowBtn = document.getElementById('addUploadRowBtn');
            const uploadRows = document.getElementById('uploadRows');
            const uploadAllBtn = document.getElementById('uploadAllBtn');
            const tokenVal = document.getElementById('tokenField').value;
            const registrationVal = document.getElementById('registrationField').value;

            let uploadRowCounter = 0;
            function addUploadRow(){
                uploadRowCounter += 1;
                const rowId = uploadRowCounter;
                const row = document.createElement('div');
                row.className = 'upload-row';
                row.setAttribute('data-row', String(rowId));
                row.setAttribute('style','margin-bottom:15px; padding: 15px; border: 1px solid #dee2e6; border-radius: 8px; background: #f8f9fa;');
                row.innerHTML = `
                    <div style="display:flex;gap:10px;align-items:center;">
                        <div class="form-group" style="flex:0 0 200px;">
                            <label class="form-label" style="font-size: 13px; margin-bottom: 5px;">Document Type</label>
                            <select class="form-select form-select-sm" id="docType_${rowId}">
                            <option value="business_permit">Business Permit</option>
                            <option value="dti_registration">DTI Registration</option>
                            <option value="bir_certificate">BIR Certificate</option>
                            <option value="mayors_permit">Mayor's Permit</option>
                            <option value="id_proof">Owner's Valid ID</option>
                            <option value="other" selected>Other Supporting Document</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                            <label class="form-label" style="font-size: 13px; margin-bottom: 5px;">File</label>
                            <input type="file" class="form-control form-control-sm file-input" id="docFile_${rowId}" accept=".jpg,.jpeg,.png,.pdf">
                        </div>
                        <div style="padding-top: 20px;">
                            <button class="btn btn-danger btn-sm removeRowBtn" data-row="${rowId}" title="Remove row">
                                <i class="bi bi-trash"></i>
                            </button>
                    </div>
                    </div>
                    <div class="file-size-info" id="fileSize_${rowId}" style="display:none; margin-top: 8px; font-size: 13px;"></div>
                `;
                uploadRows.appendChild(row);
                
                // Add file size preview on file selection
                const fileInput = document.getElementById(`docFile_${rowId}`);
                const sizeInfo = document.getElementById(`fileSize_${rowId}`);
                if (fileInput) {
                    fileInput.addEventListener('change', function() {
                        if (this.files && this.files.length > 0) {
                            const file = this.files[0];
                            const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                            const maxSize = 5;
                            
                            if (sizeInfo) {
                                sizeInfo.style.display = 'block';
                                if (file.size > maxSize * 1024 * 1024) {
                                    sizeInfo.innerHTML = `<span style="color: #dc3545; font-weight: 600;"><i class="bi bi-exclamation-triangle"></i> ${sizeMB}MB - Exceeds 5MB limit (will be skipped)</span>`;
                                    this.parentElement.parentElement.parentElement.style.borderColor = '#dc3545';
                                    this.parentElement.parentElement.parentElement.style.background = '#f8d7da';
                                } else {
                                    sizeInfo.innerHTML = `<span style="color: #28a745; font-weight: 600;"><i class="bi bi-check-circle"></i> ${sizeMB}MB - Valid</span>`;
                                    this.parentElement.parentElement.parentElement.style.borderColor = '#28a745';
                                    this.parentElement.parentElement.parentElement.style.background = '#d4edda';
                                }
                            }
                        } else {
                            if (sizeInfo) sizeInfo.style.display = 'none';
                            this.parentElement.parentElement.parentElement.style.borderColor = '#dee2e6';
                            this.parentElement.parentElement.parentElement.style.background = '#f8f9fa';
                        }
                        // Trigger validation check
                        checkAllFiles();
                    });
                }
            }

            if (addRowBtn) addRowBtn.onclick = () => addUploadRow();
            addUploadRow();

            if (uploadRows) uploadRows.addEventListener('click', (ev) => {
                const btn = ev.target.closest && ev.target.closest('.removeRowBtn');
                if (!btn) return;
                const rowEl = btn.closest && btn.closest('.upload-row');
                if (rowEl) {
                    rowEl.remove();
                    checkAllFiles();
                }
            });

            // Real-time validation check
            function checkAllFiles() {
                const validationWarnings = document.getElementById('validationWarnings');
                const validationList = document.getElementById('validationList');
                const uploadStatus = document.getElementById('uploadStatus');
                const rows = Array.from(uploadRows.querySelectorAll('.upload-row'));
                const maxFileSize = 5 * 1024 * 1024; // 5MB
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                
                let validCount = 0;
                let invalidFiles = [];
                
                for (const row of rows) {
                    const rowId = row.getAttribute('data-row');
                    const fileEl = document.getElementById(`docFile_${rowId}`);
                    
                    if (fileEl && fileEl.files && fileEl.files.length > 0) {
                        const file = fileEl.files[0];
                        const fileName = file.name;
                        const fileSize = file.size;
                        const fileType = file.type;
                        
                        if (fileSize > maxFileSize) {
                            const sizeMB = (fileSize / (1024 * 1024)).toFixed(2);
                            invalidFiles.push(`<strong>${fileName}</strong>: Too large (${sizeMB}MB, max 5MB)`);
                        } else if (!allowedTypes.includes(fileType)) {
                            invalidFiles.push(`<strong>${fileName}</strong>: Invalid file type (only JPG, PNG, PDF allowed)`);
                        } else {
                            validCount++;
                        }
                    }
                }
                
                // Update validation warnings
                if (invalidFiles.length > 0) {
                    validationList.innerHTML = invalidFiles.map(msg => `<li>${msg}</li>`).join('');
                    validationWarnings.style.display = 'block';
                } else {
                    validationWarnings.style.display = 'none';
                }
                
                // Update status
                if (validCount > 0 && invalidFiles.length > 0) {
                    uploadStatus.innerHTML = `<span style="color: #856404;"><i class="bi bi-info-circle"></i> ${validCount} valid file(s) will be uploaded. Invalid files will be skipped.</span>`;
                } else if (validCount > 0) {
                    uploadStatus.innerHTML = `<span style="color: #155724;"><i class="bi bi-check-circle"></i> ${validCount} file(s) ready to upload</span>`;
                } else if (invalidFiles.length > 0) {
                    uploadStatus.innerHTML = `<span style="color: #721c24;"><i class="bi bi-x-circle"></i> No valid files to upload</span>`;
                } else {
                    uploadStatus.innerHTML = '';
                }
            }

            if (uploadAllBtn) uploadAllBtn.onclick = async () => {
                const rows = Array.from(uploadRows.querySelectorAll('.upload-row'));
                const uploads = [];
                const maxFileSize = 5 * 1024 * 1024; // 5MB in bytes
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                let skippedFiles = [];
                
                for (const row of rows) {
                    const rowId = row.getAttribute('data-row');
                    const typeEl = document.getElementById(`docType_${rowId}`);
                    const fileEl = document.getElementById(`docFile_${rowId}`);
                    
                    if (fileEl && fileEl.files && fileEl.files.length > 0) {
                        const file = fileEl.files[0];
                        const type = typeEl ? typeEl.value : 'other';
                        const fileName = file.name;
                        const fileSize = file.size;
                        const fileType = file.type;
                        
                        // Skip invalid files but continue with valid ones
                        if (fileSize > maxFileSize) {
                            const sizeMB = (fileSize / (1024 * 1024)).toFixed(2);
                            skippedFiles.push(`${fileName} (${sizeMB}MB - exceeds limit)`);
                            continue;
                        }
                        
                        if (!allowedTypes.includes(fileType)) {
                            skippedFiles.push(`${fileName} (invalid file type)`);
                            continue;
                        }
                        
                        const form = new FormData();
                        form.append('token', tokenVal);
                        form.append('registration_id', String(registrationVal));
                        form.append('document_type', type);
                        form.append('document', file);
                        uploads.push({ form, fileEl, fileName });
                    }
                }
                
                if (uploads.length === 0) { 
                    const uploadStatus = document.getElementById('uploadStatus');
                    uploadStatus.innerHTML = `<span style="color: #721c24;"><i class="bi bi-x-circle"></i> No valid files to upload. Please select valid files.</span>`;
                    return; 
                }
                
                // Disable button during upload
                uploadAllBtn.disabled = true;
                uploadAllBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading...';
                const uploadStatus = document.getElementById('uploadStatus');
                uploadStatus.innerHTML = '<span style="color: #0056b3;"><i class="bi bi-cloud-upload"></i> Uploading files, please wait...</span>';
                
                let successCount = 0;
                let failMsgs = [];
                
                for (const u of uploads) {
                    try {
                        const resp = await fetch('process-upload.php', { method: 'POST', body: u.form, credentials: 'same-origin' });
                        const data = await resp.text();
                        // If process-upload.php echoes success messages, do minimal check
                        if (resp.ok && /success|uploaded|complete/i.test(data)) {
                            successCount += 1;
                            u.fileEl.value = '';
                        } else {
                            failMsgs.push(`${u.fileName}`);
                        }
                    } catch (e) {
                        failMsgs.push(`${u.fileName} (network error)`);
                    }
                }
                
                // Re-enable button
                uploadAllBtn.disabled = false;
                uploadAllBtn.innerHTML = '<i class="bi bi-upload"></i> Upload Documents';
                
                // Show comprehensive results
                let resultMessage = '';
                if (successCount > 0) {
                    resultMessage += `✓ ${successCount} document(s) uploaded successfully!`;
                }
                if (skippedFiles.length > 0) {
                    resultMessage += `\n\n⚠️ Skipped ${skippedFiles.length} invalid file(s):\n- ` + skippedFiles.join('\n- ');
                }
                if (failMsgs.length > 0) {
                    resultMessage += `\n\n❌ ${failMsgs.length} upload(s) failed:\n- ` + failMsgs.join('\n- ');
                }
                
                if (successCount > 0) {
                    alert(resultMessage);
                    location.reload();
                } else {
                    uploadStatus.innerHTML = `<span style="color: #721c24;"><i class="bi bi-exclamation-circle"></i> All uploads failed. Please try again.</span>`;
                    if (resultMessage) alert(resultMessage);
                }
            };

            // ==================== GALLERY UPLOAD FUNCTIONALITY ====================
            const galleryInput = document.getElementById('galleryInput');
            const galleryDropZone = document.getElementById('galleryDropZone');
            const galleryPreview = document.getElementById('galleryPreview');
            const galleryThumbnails = document.getElementById('galleryThumbnails');
            const uploadGalleryBtn = document.getElementById('uploadGalleryBtn');
            const galleryStatus = document.getElementById('galleryStatus');
            let selectedGalleryFiles = [];

            // File input change handler
            if (galleryInput) {
                galleryInput.addEventListener('change', function() {
                    handleGalleryFiles(this.files);
                });
            }

            // Drag and drop handlers
            if (galleryDropZone) {
                galleryDropZone.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.style.background = '#e9ecef';
                    this.style.borderColor = '#764ba2';
                });

                galleryDropZone.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    this.style.background = '';
                    this.style.borderColor = '#667eea';
                });

                galleryDropZone.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.style.background = '';
                    this.style.borderColor = '#667eea';
                    handleGalleryFiles(e.dataTransfer.files);
                });
            }

            function handleGalleryFiles(files) {
                const maxSize = 5 * 1024 * 1024; // 5MB
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
                // Don't reset array - accumulate files instead
                // selectedGalleryFiles = [];

                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    if (file.size <= maxSize && (file.type === 'image/jpeg' || file.type === 'image/png')) {
                        selectedGalleryFiles.push(file);
                    }
                }

                if (selectedGalleryFiles.length > 0) {
                    displayGalleryThumbnails();
                    galleryPreview.style.display = 'block';
                } else {
                    alert('No valid images selected. Please select JPG or PNG files under 5MB.');
                }
            }

            function displayGalleryThumbnails() {
                galleryThumbnails.innerHTML = '';
                selectedGalleryFiles.forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                        const col = document.createElement('div');
                        col.className = 'col-md-3 col-sm-4 col-6';
                        col.innerHTML = `
                            <div class="card" style="position: relative;">
                                <img src="${e.target.result}" class="card-img-top" style="height: 150px; object-fit: cover;">
                                <div class="card-body p-2">
                                    <small class="text-muted d-block text-truncate">${file.name}</small>
                                    <small class="text-success"><i class="bi bi-check-circle"></i> ${sizeMB}MB</small>
                                </div>
                                <button type="button" class="btn btn-danger btn-sm" 
                                        style="position: absolute; top: 5px; right: 5px; padding: 2px 8px;"
                                        onclick="removeGalleryImage(${index})">
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        `;
                        galleryThumbnails.appendChild(col);
                    };
                    reader.readAsDataURL(file);
                });
            }

            window.removeGalleryImage = function(index) {
                selectedGalleryFiles.splice(index, 1);
                if (selectedGalleryFiles.length > 0) {
                    displayGalleryThumbnails();
                } else {
                    galleryPreview.style.display = 'none';
                    galleryInput.value = '';
                }
            };

            // Upload gallery photos
            if (uploadGalleryBtn) {
                uploadGalleryBtn.onclick = async function() {
                    if (selectedGalleryFiles.length === 0) {
                        alert('Please select at least one photo to upload.');
                        return;
                    }

                    uploadGalleryBtn.disabled = true;
                    uploadGalleryBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading...';
                    galleryStatus.innerHTML = '<span style="color: #0056b3;"><i class="bi bi-cloud-upload"></i> Uploading gallery photos...</span>';

                    let successCount = 0;
                    let failCount = 0;

                    for (const file of selectedGalleryFiles) {
                        const formData = new FormData();
                        formData.append('token', tokenVal);
                        formData.append('registration_id', registrationVal);
                        formData.append('gallery_photo', file);

                        try {
                            const resp = await fetch('process-gallery-upload.php', {
                                method: 'POST',
                                body: formData,
                                credentials: 'same-origin'
                            });
                            const data = await resp.json();
                            if (data && data.success) {
                                successCount++;
                                console.log('✓ Gallery upload success:', file.name, data);
                            } else {
                                failCount++;
                                console.error('✗ Gallery upload failed:', file.name);
                                console.error('   Error message:', data.message || 'Unknown error');
                                console.error('   Full response:', JSON.stringify(data));
                            }
                        } catch (e) {
                            failCount++;
                            console.error('✗ Gallery upload exception:', file.name);
                            console.error('   Error:', e.message || e);
                        }
                    }

                    uploadGalleryBtn.disabled = false;
                    uploadGalleryBtn.innerHTML = '<i class="bi bi-upload"></i> Upload Gallery Photos';

                    if (successCount > 0) {
                        alert(`✓ Success! ${successCount} photo(s) uploaded to gallery.` + (failCount > 0 ? `\n❌ ${failCount} upload(s) failed.` : ''));
                        selectedGalleryFiles = [];
                        galleryPreview.style.display = 'none';
                        galleryInput.value = '';
                        location.reload();
                    } else {
                        galleryStatus.innerHTML = '<span style="color: #721c24;"><i class="bi bi-exclamation-circle"></i> All uploads failed. Please try again.</span>';
                }
            };
            }
        })();
    </script>
</body>
</html>
