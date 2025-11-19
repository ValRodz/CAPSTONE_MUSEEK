<?php
session_start();
include '../../shared/config/db pdo.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'owner') {
    header('Location: ../../auth/php/login.php');
    exit();
}

$ownerId = (int)$_SESSION['user_id'];

// Fetch approved studios only for selector
$studios = [];
try {
    $stmt = $pdo->prepare("SELECT StudioID, StudioName FROM studios WHERE OwnerID = ? AND approved_by_admin IS NOT NULL AND approved_at IS NOT NULL ORDER BY StudioName ASC");
    $stmt->execute([$ownerId]);
    $studios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $studios = [];
}

// Default selected studio
$selectedStudioId = isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0;
if ($selectedStudioId <= 0 && !empty($studios)) {
    $selectedStudioId = (int)$studios[0]['StudioID'];
}

// Check if user has any approved studios
$hasApprovedStudios = !empty($studios);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Studio Gallery</title>
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary-color: #e11d48;
            --primary-hover: #f43f5e;
            --header-height: 64px;
            --card-bg: #0f0f0f;
            --body-bg: #0a0a0a;
            --border-color: #222222;
            --text-primary: #ffffff;
            --text-secondary: #a1a1aa;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
        }

        body { font-family: "Inter", sans-serif; background-color: var(--body-bg); color: var(--text-primary); }

        /* Header */
        .header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--body-bg);
        }
        .toggle-sidebar {
            background: none;
            border: none;
            color: var(--text-primary);
            cursor: pointer;
            font-size: 1.25rem;
            margin-right: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 0.375rem;
            transition: background-color 0.2s;
        }
        .toggle-sidebar:hover { background-color: rgba(255,255,255,0.05); }
        .page-title { font-size: 1.25rem; font-weight: 600; }

        /* Container and Cards (aligned with dashboard.php) */
        .dashboard-container {
            flex: 1;
            overflow-y: visible;
            overflow-x: hidden;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            min-height: calc(100vh - var(--header-height));
        }
        .dashboard-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .dashboard-card-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dashboard-card-title { font-size: 0.95rem; font-weight: 600; }
        .dashboard-card-subtitle { font-size: 0.8rem; color: var(--text-secondary); }
        .dashboard-card-body { padding: 1rem; }

        /* Controls */
        .controls-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }
        .select, input[type="file"], input[type="text"] {
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            border: 1px solid var(--border-color);
            background-color: #141414;
            color: var(--text-primary);
        }
        .btn {
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            border: 1px solid transparent;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s, border-color 0.2s;
        }
        .btn-primary { background: var(--primary-color); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: #1f1f1f; color: #fff; border-color: var(--border-color); }
        .btn-secondary:hover { background: #2a2a2a; }
        .btn-danger { background: #991b1b; color: #fff; }
        .btn-danger:hover { background: #b91c1c; }

        /* Gallery Grid */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.75rem;
        }
        .gallery-card {
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            background: #141414;
            padding: 0.5rem;
            display: flex;
            flex-direction: column;
        }
        .gallery-card img {
            width: 100%;
            height: 140px;
            object-fit: cover;
            border-radius: 0.375rem;
            border: 1px solid var(--border-color);
            margin-bottom: 0.5rem;
        }
        .gallery-card input[type="text"] { width: 100%; }
        .gallery-card .btn-danger { margin-top: 0.5rem; width: 100%; }
    </style>
</head>
<body>
<?php include 'sidebar_netflix.php'; ?>
<div class="main-content">
    <div class="header">
        <div class="page-title">Studio Gallery</div>
    </div>
    <div class="dashboard-container">
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <div>
                    <div class="dashboard-card-title">Manage Gallery</div>
                    <div class="dashboard-card-subtitle">Upload, caption, reorder, and delete images</div>
                </div>
            </div>
            <div class="dashboard-card-body">
                <?php if (!$hasApprovedStudios): ?>
                    <div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
                        <i class="fas fa-exclamation-circle" style="font-size: 3rem; margin-bottom: 1rem; color: var(--warning-color);"></i>
                        <p style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem;">No Approved Studios</p>
                        <p style="font-size: 0.9rem;">You need to have at least one approved studio before you can manage gallery images.</p>
                    </div>
                <?php else: ?>
                    <div class="controls-row">
                        <label for="studioSelect" style="color:var(--text-secondary)">Studio</label>
                        <select id="studioSelect" class="select">
                            <?php foreach ($studios as $s): ?>
                                <option value="<?= (int)$s['StudioID'] ?>" <?= $selectedStudioId === (int)$s['StudioID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['StudioName'] ?: ('Studio #' . $s['StudioID'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <form id="galleryUploadForm" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <label for="galleryFiles" class="btn btn-secondary" style="cursor:pointer;margin:0;">
                                <i class="fas fa-images"></i> Choose Images (Multiple)
                            </label>
                            <input type="file" name="images[]" id="galleryFiles" accept=".jpg,.jpeg,.png,.webp" multiple style="display:none;">
                            <span id="fileCount" style="color:var(--text-secondary);font-size:0.85rem;"></span>
                            <button type="button" id="galleryUploadBtn" class="btn btn-primary" disabled>
                                <i class="fas fa-upload"></i> Upload
                            </button>
                        </form>

                        <button type="button" id="saveOrderBtn" class="btn btn-secondary">
                            <i class="fas fa-save"></i> Save Order
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($hasApprovedStudios): ?>
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <div class="dashboard-card-title">Images</div>
                </div>
                <div class="dashboard-card-body">
                    <div id="galleryGrid" class="gallery-grid"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    const grid = document.getElementById('galleryGrid');
    const studioSelect = document.getElementById('studioSelect');
    const uploadBtn = document.getElementById('galleryUploadBtn');
    const uploadForm = document.getElementById('galleryUploadForm');
    const saveOrderBtn = document.getElementById('saveOrderBtn');
    const fileInput = document.getElementById('galleryFiles');
    const fileCount = document.getElementById('fileCount');

    // Only run if elements exist (i.e., user has approved studios)
    if (!studioSelect || !uploadBtn) return;

    // Handle file selection
    fileInput.addEventListener('change', (e) => {
        const files = e.target.files;
        if (files.length > 0) {
            fileCount.textContent = `${files.length} file${files.length > 1 ? 's' : ''} selected`;
            uploadBtn.disabled = false;
        } else {
            fileCount.textContent = '';
            uploadBtn.disabled = true;
        }
    });

    function fetchGallery() {
        const studioId = studioSelect.value;
        fetch('studio_gallery.php?action=list&studio_id=' + encodeURIComponent(studioId))
            .then(r => r.json())
            .then(d => {
                grid.innerHTML = '';
                if (!d.success) {
                    grid.innerHTML = '<p style="color:var(--text-secondary);padding:1rem;">No images found. Upload some to get started!</p>';
                    return;
                }
                d.images.forEach(img => {
                    const card = document.createElement('div');
                    card.className = 'gallery-card';
                    card.dataset.imageId = img.image_id;

                    const picture = document.createElement('img');
                    picture.src = '../../' + img.file_path;
                    picture.alt = img.caption || '';
                    card.appendChild(picture);

                    const cap = document.createElement('input');
                    cap.type = 'text';
                    cap.placeholder = 'Caption';
                    cap.value = img.caption || '';
                    cap.addEventListener('change', () => {
                        const fd = new FormData();
                        fd.append('action', 'update_caption');
                        fd.append('image_id', img.image_id);
                        fd.append('caption', cap.value);
                        fetch('studio_gallery.php', { method: 'POST', body: fd });
                    });
                    card.appendChild(cap);

                    const del = document.createElement('button');
                    del.innerHTML = '<i class="fas fa-trash"></i> Delete';
                    del.className = 'btn btn-danger';
                    del.style = 'margin-top:6px;width:100%;';
                    del.addEventListener('click', () => {
                        if (!confirm('Delete this image?')) return;
                        const fd = new FormData();
                        fd.append('action', 'delete');
                        fd.append('image_id', img.image_id);
                        fetch('studio_gallery.php', { method: 'POST', body: fd })
                            .then(r => r.json())
                            .then(x => { if (x.success) fetchGallery(); });
                    });
                    card.appendChild(del);

                    card.draggable = true;
                    card.addEventListener('dragstart', e => e.dataTransfer.setData('text/plain', img.image_id));
                    card.addEventListener('dragover', e => e.preventDefault());
                    card.addEventListener('drop', e => {
                        e.preventDefault();
                        const draggedId = e.dataTransfer.getData('text/plain');
                        const target = e.currentTarget;
                        const draggedEl = [...grid.children].find(c => c.dataset.imageId === draggedId);
                        if (draggedEl) {
                            grid.insertBefore(draggedEl, target);
                        }
                    });

                    grid.appendChild(card);
                });
            });
    }

    uploadBtn.addEventListener('click', () => {
        const files = fileInput.files;
        if (files.length === 0) {
            alert('Please select at least one image');
            return;
        }
        
        // Show uploading state
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        
        const fd = new FormData(uploadForm);
        fd.append('action', 'upload');
        fd.append('studio_id', studioSelect.value);
        fetch('studio_gallery.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    fetchGallery();
                    fileInput.value = '';
                    fileCount.textContent = '';
                    uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';
                    uploadBtn.disabled = true;
                    alert(`Successfully uploaded ${files.length} image${files.length > 1 ? 's' : ''}!`);
                } else {
                    alert('Upload failed: ' + (d.message || 'Unknown error'));
                    uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';
                    uploadBtn.disabled = false;
                }
            })
            .catch(err => {
                console.error('Upload error:', err);
                alert('Upload failed. Please try again.');
                uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';
                uploadBtn.disabled = false;
            });
    });

    saveOrderBtn.addEventListener('click', () => {
        const order = [...grid.children].map(c => c.dataset.imageId);
        if (order.length === 0) {
            alert('No images to reorder');
            return;
        }
        
        saveOrderBtn.disabled = true;
        saveOrderBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        
        const fd = new FormData();
        fd.append('action', 'reorder');
        fd.append('studio_id', studioSelect.value);
        order.forEach(id => fd.append('order[]', id));
        fetch('studio_gallery.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    fetchGallery();
                    alert('Order saved successfully!');
                } else {
                    alert('Failed to save order');
                }
                saveOrderBtn.disabled = false;
                saveOrderBtn.innerHTML = '<i class="fas fa-save"></i> Save Order';
            });
    });

    studioSelect.addEventListener('change', fetchGallery);

    fetchGallery();
})();
</script>
</body>
</html>