<?php

require __DIR__ . '/api/webhook/firestore_client.php';

// ─── Global Context ────────────────────────────────────────────────────────────
$locationIdSafe = '';
$backendApiUrl = 'https://smspro-api.nolacrm.io';

/**
 * Send a full HTML page and exit.
 */
function render_page(string $title, string $body_html): void
{
    global $locationIdSafe, $backendApiUrl;

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML




















<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — NOLA SMS Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/0.160.0/three.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-family: 'Poppins', system-ui, -apple-system, sans-serif; background: #fdfdfd; color: #1a1a1a; -webkit-font-smoothing: antialiased; }
        body { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 20px; overflow: hidden; }

        #antigravity-bg { 
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background: radial-gradient(circle at center, #ffffff 0%, #f0f7ff 100%);
        }

        .unified-card { 
            max-width: 440px; width: 100%; 
            background: rgba(255, 255, 255, 0.72); 
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-radius: 36px; padding: 44px 32px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.06), inset 0 0 0 1px rgba(255,255,255,0.4); 
            border: 1px solid rgba(43,131,250,0.1); text-align: center;
            animation: card-in 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
            z-index: 10;
        }
        @keyframes card-in { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }

        .success-ring { position: relative; display: inline-flex; margin: 0 auto 31px; }
        .success-ring::before { 
            content: ''; position: absolute; inset: -10px; border-radius: 50%; 
            border: 3px solid #2b83fa; opacity: 0.4; 
            animation: pulse-ring 2.5s cubic-bezier(0.4, 0, 0.6, 1) infinite; 
        }
        @keyframes pulse-ring { 
            0% { transform: scale(0.95); opacity: 0.6; } 70% { transform: scale(1.35); opacity: 0; } 100% { transform: scale(1.35); opacity: 0; } 
        }

        .success-icon { 
            width: 72px; height: 72px; border-radius: 50%; 
            background: #2b83fa; display: flex; align-items: center; justify-content: center; 
            z-index: 10; position: relative; box-shadow: 0 10px 24px rgba(43,131,250,0.4);
        }

        .error-icon { 
            width: 72px; height: 72px; border-radius: 50%; 
            background: #fef2f2; display: flex; align-items: center; justify-content: center; 
            z-index: 10; position: relative; box-shadow: 0 10px 24px rgba(220,38,38,0.1);
            border: 1px solid #fee2e2;
        }

        h1 { font-size: 30px; font-weight: 800; letter-spacing: -1.4px; margin-bottom: 6px; color: #111; line-height: 1; }
        p.subtitle { font-size: 16px; color: #6e6e73; margin-bottom: 36px; line-height: 1.4; font-weight: 500; }

        .btn-primary { 
            display: inline-flex; align-items: center; justify-content: center; gap: 10px; 
            width: auto; padding: 14px 44px; border-radius: 99px; 
            background: #2b83fa; color: #fff; font-size: 16px; font-weight: 700; 
            text-decoration: none; transition: all 0.23s cubic-bezier(0.4, 0, 0.2, 1); 
            box-shadow: 0 6px 16px rgba(43,131,250,0.35); border: none; cursor: pointer;
            margin: 0 auto;
        }
        .btn-primary:hover { background: #1d6bd4; transform: translateY(-3px); box-shadow: 0 12px 32px rgba(43,131,250,0.45); }
        .btn-primary:active { transform: scale(0.97); }

        .sender-toggle { 
            font-size: 13px; font-weight: 700; color: #6e6e73; 
            cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 99px; transition: all 0.2s;
            background: #f8f8f8; border: 1px solid rgba(0,0,0,0.03);
            margin: 0 auto;
        }
        .sender-toggle:hover { background: #f0f0f0; color: #111; transform: translateY(-1px); }

        /* MODAL STYLES */
        .modal-overlay { 
            position: fixed; inset: 0; background: rgba(0,0,0,0.5); 
            backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; 
            z-index: 1000; animation: fade-in 0.3s ease; 
        }
        .modal-content { 
            background: #fff; width: 92%; max-width: 440px; border-radius: 28px; 
            padding: 32px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); 
            animation: modal-up 0.4s cubic-bezier(0.16, 1, 0.3, 1) both;
            text-align: left; position: relative;
        }
        @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
        @keyframes modal-up { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .modal-title { font-size: 18px; font-weight: 800; color: #111; letter-spacing: -0.4px; }
        .modal-close { cursor: pointer; color: #aaa; transition: 0.2s; padding: 4px; }
        .modal-close:hover { color: #111; transform: scale(1.1); }

        label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #9aa0a6; margin-bottom: 8px; letter-spacing: 0.05em; }
        input, textarea { 
            width: 100%; padding: 14px; border-radius: 14px; border: 1px solid #e0e0e0; 
            background: #fafafa; font-family: inherit; font-size: 14px; margin-bottom: 16px; 
            outline: none; transition: all 0.2s;
        }
        input:focus, textarea:focus { border-color: #2b83fa; background: #fff; box-shadow: 0 0 0 4px rgba(43,131,250,0.1); }

        .btn-submit { 
            background: #2b83fa; color: #fff; 
            border: none; padding: 16px; border-radius: 18px; font-weight: 700; 
            cursor: pointer; width: 100%; font-size: 14px; transition: 0.2s; 
            box-shadow: 0 4px 12px rgba(43,131,250,0.2); margin-top: 8px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(43,131,250,0.3); }

        .tutorial-link { 
            font-size: 11px; color: #a1a1aa; text-decoration: underline; 
            cursor: pointer; margin-top: 16px; display: inline-block;
            transition: color 0.15s; font-weight: 600; text-underline-offset: 4px;
        }
        .tutorial-link:hover { color: #6e6e73; }
        
        .hidden { display: none !important; }
        
        .error-pre { margin-top: 12px; font-size: 10px; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 10px; overflow: auto; max-height: 120px; text-align: left; white-space: pre-wrap; word-break: break-all; font-family: monospace; }
    </style>
</head>
<body>
    <canvas id="antigravity-bg"></canvas>

    <div class="unified-card">
        {$body_html}
    </div>

    <!-- SENDER ID MODAL -->
    <div id="sender-modal" class="modal-overlay" onclick="if(event.target === this) toggleModal('sender-modal')">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Request Sender ID</h3>
                <div class="modal-close" onclick="toggleModal('sender-modal')">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </div>
            </div>

            <form id="sender-form" onsubmit="handleSenderSubmit(event)">
                <div id="sender-success" class="hidden" style="text-align:center; padding: 20px 0;">
                    <div style="width:48px; height:48px; background:#f0fdf4; color:#16a34a; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <p style="color: #111; font-weight: 800; font-size: 16px; margin-bottom: 4px;">Request Submitted!</p>
                    <p style="font-size: 13px; color: #6e6e73;">Review takes about 5 business days.</p>
                </div>

                <div id="form-fields">
                    <div id="sender-error" class="hidden" style="color:#ef4444; font-size:12px; font-weight:600; margin-bottom:16px;"></div>
                    
                    <label>Sender Name</label>
                    <input id="sender-name" type="text" maxlength="11" placeholder="ex. MyBrand" required>
                    
                    <label>Purpose</label>
                    <textarea id="sender-purpose" rows="2" placeholder="What will you be using it for?" required></textarea>
                    
                    <label>Sample Message</label>
                    <textarea id="sender-sample" rows="2" placeholder="Specific example of messages you'll send." required></textarea>
                    
                    <button type="submit" id="submit-btn" class="btn-submit">Submit Request</button>

                    <div style="margin-top: 20px; border-radius: 12px; background: #fffcf0; border: 1px solid #fdf2c2; padding: 12px; text-align: center;">
                        <p style="font-size: 11px; color: #856404; font-weight: 500; line-height: 1.4;">
                            <strong>Note:</strong> It may take a few business days for your sender name to be approved by the carrier network.
                        </p>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- HOW IT WORKS MODAL -->
    <div id="how-modal" class="modal-overlay" onclick="if(event.target === this) toggleModal('how-modal')">
        <div class="modal-content" style="text-align: center;">
            <div class="modal-header">
                <div style="width:24px;"></div>
                <h3 class="modal-title">How it Works</h3>
                <div class="modal-close" onclick="toggleModal('how-modal')">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </div>
            </div>

            <div id="tutorial-step-1" class="tutorial-step">
                <div style="width:56px; height:56px; background:#f0f7ff; color:#2b83fa; font-size:24px; font-weight:900; border-radius:18px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">1</div>
                <h4 style="font-size:16px; font-weight:800; margin-bottom:8px; color:#111;">Open Nola SMS Pro</h4>
                <p style="font-size:13px; color:#444; line-height:1.5; margin-bottom:24px;">Open the Nola SMS Pro application from your GoHighLevel sidebar menu.</p>
                <div style="display:flex; justify-content:center;">
                    <button class="btn-primary" onclick="nextStep(2)" style="width:auto; padding:14px 24px; font-size:14px; border-radius:12px;">Next Step &rarr;</button>
                </div>
            </div>

            <div id="tutorial-step-2" class="tutorial-step hidden">
                <div style="width:56px; height:56px; background:#f0f7ff; color:#2b83fa; font-size:24px; font-weight:900; border-radius:18px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">2</div>
                <h4 style="font-size:16px; font-weight:800; margin-bottom:8px; color:#111;">Select a Contact</h4>
                <p style="font-size:13px; color:#444; line-height:1.5; margin-bottom:24px;">Choose any contact from your GHL CRM to start a message thread.</p>
                <div style="display:flex; justify-content:center;">
                    <button class="btn-primary" onclick="nextStep(3)" style="width:auto; padding:14px 24px; font-size:14px; border-radius:12px;">Next Step &rarr;</button>
                </div>
            </div>

            <div id="tutorial-step-3" class="tutorial-step hidden">
                <div style="width:56px; height:56px; background:#f0fdf4; color:#16a34a; font-size:24px; font-weight:900; border-radius:18px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">3</div>
                <h4 style="font-size:16px; font-weight:800; margin-bottom:8px; color:#111;">Ready to Send!</h4>
                <p style="font-size:13px; color:#444; line-height:1.5; margin-bottom:24px;">Your account starts with 10 free credits to get you up and running immediately.</p>
                <div style="display:flex; justify-content:center;">
                    <button class="btn-primary" onclick="toggleModal('how-modal')" style="width:auto; padding:14px 24px; font-size:14px; border-radius:12px;">Got it!</button>
                </div>
            </div>
        </div>
    </div>

    <script>
      const LOCATION_ID = '{$locationIdSafe}';
      const API_BASE    = '{$backendApiUrl}';

      (function() {
        const canvas = document.getElementById('antigravity-bg');
        const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
        renderer.setPixelRatio(window.devicePixelRatio);
        renderer.setSize(window.innerWidth, window.innerHeight);

        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(35, window.innerWidth / window.innerHeight, 0.1, 1000);
        camera.position.z = 50;

        const count = 300;
        const magnetRadius = 6;
        const ringRadius = 7;
        const waveSpeed = 0.4;
        const waveAmplitude = 1;
        const particleSize = 1.5;
        const lerpSpeed = 0.05;
        const autoAnimate = true;
        const particleVariance = 1;
        const rotationSpeed = 0;
        const depthFactor = 1;
        const pulseSpeed = 3;
        const fieldStrength = 10;
        const color = '#2b83fa';

        const geometry = new THREE.CapsuleGeometry(0.1, 0.4, 4, 8);
        const material = new THREE.MeshBasicMaterial({ color: new THREE.Color(color) });
        const mesh = new THREE.InstancedMesh(geometry, material, count);
        scene.add(mesh);

        const dummy = new THREE.Object3D();
        const particles = [];
        
        function initParticles() {
            const aspect = window.innerWidth / window.innerHeight;
            const h = 2 * Math.tan((camera.fov * Math.PI) / 360) * camera.position.z;
            const w = h * aspect;

            for (let i = 0; i < count; i++) {
                const x = (Math.random() - 0.5) * w;
                const y = (Math.random() - 0.5) * h;
                const z = (Math.random() - 0.5) * 20;
                particles.push({
                    t: Math.random() * 100,
                    speed: 0.01 + Math.random() / 200,
                    mx: x, my: y, mz: z,
                    cx: x, cy: y, cz: z,
                    randomRadiusOffset: (Math.random() - 0.5) * 2
                });
            }
        }
        initParticles();

        let mouse = new THREE.Vector2(0, 0);
        let virtualMouse = new THREE.Vector2(0, 0);
        let lastMouseMoveTime = 0;

        window.addEventListener('mousemove', (e) => {
            mouse.x = (e.clientX / window.innerWidth) * 2 - 1;
            mouse.y = -(e.clientY / window.innerHeight) * 2 + 1;
            lastMouseMoveTime = Date.now();
        });

        window.addEventListener('resize', () => {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
        });

        function animate() {
            requestAnimationFrame(animate);
            const time = performance.now() / 1000;
            
            const aspect = window.innerWidth / window.innerHeight;
            const vh = 2 * Math.tan((camera.fov * Math.PI) / 360) * camera.position.z;
            const vw = vh * aspect;

            let destX = (mouse.x * vw) / 2;
            let destY = (mouse.y * vh) / 2;

            if (autoAnimate && Date.now() - lastMouseMoveTime > 2000) {
                destX = Math.sin(time * 0.5) * (vw / 4);
                destY = Math.cos(time * 0.5 * 2) * (vh / 4);
            }

            virtualMouse.x += (destX - virtualMouse.x) * 0.05;
            virtualMouse.y += (destY - virtualMouse.y) * 0.05;

            const globalRotation = time * rotationSpeed;

            for (let i = 0; i < count; i++) {
                const p = particles[i];
                p.t += p.speed / 2;

                const projectionFactor = 1 - p.cz / 50;
                const pTargetX = virtualMouse.x * projectionFactor;
                const pTargetY = virtualMouse.y * projectionFactor;

                const dx = p.mx - pTargetX;
                const dy = p.my - pTargetY;
                const dist = Math.sqrt(dx * dx + dy * dy);

                let targetX = p.mx;
                let targetY = p.my;
                let targetZ = p.mz * depthFactor;

                if (dist < magnetRadius) {
                    const angle = Math.atan2(dy, dx) + globalRotation;
                    const wave = Math.sin(p.t * waveSpeed + angle) * (0.5 * waveAmplitude);
                    const deviation = p.randomRadiusOffset * (5 / (fieldStrength + 0.1));
                    const currentRingRadius = ringRadius + wave + deviation;

                    targetX = pTargetX + currentRingRadius * Math.cos(angle);
                    targetY = pTargetY + currentRingRadius * Math.sin(angle);
                    targetZ = p.mz * depthFactor + Math.sin(p.t) * (1 * waveAmplitude * depthFactor);
                }

                p.cx += (targetX - p.cx) * lerpSpeed;
                p.cy += (targetY - p.cy) * lerpSpeed;
                p.cz += (targetZ - p.cz) * lerpSpeed;

                dummy.position.set(p.cx, p.cy, p.cz);
                dummy.lookAt(pTargetX, pTargetY, p.cz);
                dummy.rotateX(Math.PI / 2);

                const currentDist = Math.sqrt(Math.pow(p.cx - pTargetX, 2) + Math.pow(p.cy - pTargetY, 2));
                const distFromRing = Math.abs(currentDist - ringRadius);
                let scaleFactor = Math.max(0, Math.min(1, 1 - distFromRing / 10));
                const finalScale = scaleFactor * (0.8 + Math.sin(p.t * pulseSpeed) * 0.2 * particleVariance) * particleSize;
                
                dummy.scale.set(finalScale, finalScale, finalScale);
                dummy.updateMatrix();
                mesh.setMatrixAt(i, dummy.matrix);
            }

            mesh.instanceMatrix.needsUpdate = true;
            renderer.render(scene, camera);
        }
        animate();
      })();

      function toggleModal(id) {
        const el = document.getElementById(id);
        if (el && el.style.display === 'flex') {
          el.style.display = 'none';
          if (id === 'how-modal') resetTutorial();
        } else if (el) {
          el.style.display = 'flex';
        }
      }

      function nextStep(step) {
        document.querySelectorAll('.tutorial-step').forEach(s => s.classList.add('hidden'));
        document.getElementById('tutorial-step-' + step).classList.remove('hidden');
      }

      function resetTutorial() {
        document.querySelectorAll('.tutorial-step').forEach(s => s.classList.add('hidden'));
        document.getElementById('tutorial-step-1').classList.remove('hidden');
      }

      async function handleSenderSubmit(e) {
        e.preventDefault();
        const btn = document.getElementById('submit-btn');
        btn.disabled = true;
        btn.innerHTML = 'Submitting...';

        try {
          const res = await fetch(API_BASE + '/api/sender-requests', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-GHL-Location-ID': LOCATION_ID },
            body: JSON.stringify({
              location_id:    LOCATION_ID,
              requested_id:   document.getElementById('sender-name').value.trim().toUpperCase(),
              purpose:        document.getElementById('sender-purpose').value.trim(),
              sample_message: document.getElementById('sender-sample').value.trim()
            }),
          });
          if (!res.ok) throw new Error();
          document.getElementById('form-fields').classList.add('hidden');
          document.getElementById('sender-success').classList.remove('hidden');
        } catch {
          document.getElementById('sender-error').textContent = "Failed to submit. Try again.";
          document.getElementById('sender-error').classList.remove('hidden');
          btn.disabled = false;
          btn.innerHTML = 'Submit Request';
        }
      }
    </script>
</body>
</html>
HTML;
}

/**
 * Stop early with a styled error page.
 */
function render_error(string $message, array $details = []): void
{
    $msg_safe = htmlspecialchars($message);
    $details_html = '';
    if (!empty($details)) {
        $json = htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT));
        $details_html = "<pre class=\"error-pre\">{$json}</pre>";
    }

    $reinstall_url = 'https://marketplace.leadconnectorhq.com/oauth/chooselocation?response_type=code&redirect_uri=https%3A%2F%2Fsmspro-api.nolacrm.io%2Foauth%2Fcallback&client_id=6999da2b8f278296d95f7274-mmn30t4f&scope=locations.readonly+workflows.readonly+conversations%2Fmessage.readonly+conversations.readonly+conversations.write+contacts.readonly+contacts.write+conversations%2Fmessage.write&version_id=6999da2b8f278296d95f7274';

    $body = <<<HTML
        <div class="error-icon" style="margin: 0 auto 32px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <h1>Installation Failed</h1>
        <p class="subtitle" style="margin-bottom: 24px;">{$msg_safe}</p>
        {$details_html}
        <div style="margin-top: 32px;">
            <a href="{$reinstall_url}" class="btn-primary">Try Again</a>
        </div>
HTML;
    render_page('Installation Failed', $body);
    exit;
}

// ─── Preview Logic ────────────────────────────────────────────────────────────
if (isset($_GET['test'])) {
    if ($_GET['test'] === 'success') {
        $locationIdSafe = 'test_location_123';
        $locationNameDisplay = $locationName;
        $dashboardUrl = '#';
        $body = <<<HTML
            <div class="success-ring"><div class="success-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div></div>
            <h1>Success!</h1>
            <p class="subtitle"><b>{$locationNameDisplay}</b> is now connected to <b>NOLA SMS Pro</b></p>
            <div style="display:flex; flex-direction:column; gap:16px; margin-bottom:32px;">
                <a href="{$dashboardUrl}" class="btn-primary">Open Dashboard</a>
                <div class="sender-toggle" onclick="toggleModal('sender-modal')">Request Sender ID</div>
            </div>
            <div onclick="toggleModal('how-modal')" class="tutorial-link">How it works & Credits</div>
             <p style="font-size: 10px; color: #ddd; margin-top: 40px; font-weight: 500;">© 2026 Powered by NOLA CRM</p>
HTML;
        render_page('Success!', $body);
        exit;
    }
    if ($_GET['test'] === 'error') {
        render_error('Test error message appearing here.', ['debug' => 'active', 'timestamp' => time()]);
    }
}

// ─── OAuth Config ──────────────────────────────────────────────────────────────
$clientId = getenv('GHL_CLIENT_ID');
$clientSecret = getenv('GHL_CLIENT_SECRET');
$redirectUri = 'https://smspro-api.nolacrm.io/oauth/callback';

if (!$clientId || !$clientSecret)
    render_error('Server configuration error: GHL credentials are not set up.');
if (!isset($_GET['code']))
    render_error('No authorization code was received.');

$code = $_GET['code'];
$state = $_GET['state'] ?? null;

// ─── Token Exchange ────────────────────────────────────────────────────────────
$ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'grant_type' => 'authorization_code',
    'code' => $code,
    'user_type' => 'Location',
    'redirect_uri' => $redirectUri,
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Version: 2021-07-28']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);
if ($httpCode !== 200 || !is_array($data))
    render_error('Authorization failed.', $data ?: []);

$locationId = $state ?? $data['locationId'] ?? $data['location_id'] ?? null;
if (!$locationId)
    render_error('No Location ID returned.');

$locationIdSafe = htmlspecialchars((string)$locationId, ENT_QUOTES, 'UTF-8');

// ─── Fetch Location Name ───────────────────────────────────────────────────────
$locationName = '';
try {
    $locCh = curl_init('https://services.leadconnectorhq.com/locations/' . $locationId);
    curl_setopt($locCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($locCh, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $data['access_token'],
        'Accept: application/json',
        'Version: 2021-07-28',
    ]);
    $locResp = curl_exec($locCh);
    $locCode = curl_getinfo($locCh, CURLINFO_HTTP_CODE);
    curl_close($locCh);

    if ($locCode === 200) {
        $locData = json_decode($locResp, true);
        $locationName = $locData['location']['name'] ?? '';
    }
}
catch (Exception $e) {
    error_log("Failed to fetch location name in callback for $locationId: " . $e->getMessage());
}

$locationNameDisplay = $locationName ? htmlspecialchars($locationName, ENT_QUOTES, 'UTF-8') : 'Your Sub-Account';
$dashboardUrl = 'https://app.nolacrm.io/v2/location/' . $locationIdSafe . '/custom-page-link/69a642aae76974824fd39bb6';

// ─── Save Tokens & Metadata to Firestore ──────────────────────────────────────
$db = get_firestore();
$now = new DateTimeImmutable();
$expiresAtUnix = time() + (int)($data['expires_in'] ?? 0);

try {
    // 1. Save main tokens
    $db->collection('ghl_tokens')->document((string)$locationId)->set([
        'access_token' => $data['access_token'] ?? null,
        'refresh_token' => $data['refresh_token'] ?? null,
        'scope' => $data['scope'] ?? null,
        'location_id' => $locationId,
        'location_name' => $locationName,
        'expires_at' => $expiresAtUnix,
        'userType' => $data['userType'] ?? 'Location',
        'companyId' => $data['companyId'] ?? '',
        'userId' => $data['userId'] ?? '',
        'raw' => $data,
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ], ['merge' => true]);

    // 2. Provision Credits for new users
    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locationId);
    $integrationRef = $db->collection('integrations')->document($intDocId);
    $integrationSnap = $integrationRef->snapshot();

    if (!$integrationSnap->exists()) {
        // First-time install — 10 free credits & clear API keys
        $integrationRef->set([
            'location_id' => $locationId,
            'location_name' => $locationName,
            'free_credits_total' => 10,
            'free_usage_count' => 0,
            'approved_sender_id' => null,
            'semaphore_api_key' => null,
            'nola_pro_api_key' => null,
            'system_default_sender' => 'NOLASMSPro',
            'installed_at' => new \Google\Cloud\Core\Timestamp($now),
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        ]);
    }
    else {
        // Re-install: preserve credits, just update name
        $integrationRef->set([
            'location_name' => $locationName,
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        ], ['merge' => true]);
    }
}
catch (Exception $e) {
    render_error('Callback authorized, but failed to save tokens: ' . $e->getMessage());
}

// ─── Render Success Page ───────────────────────────────────────────────────────
$body = <<<HTML
    <div class="success-ring">
        <div class="success-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
    </div>
    <h1>Success!</h1>
    <p class="subtitle"><b>{$locationNameDisplay}</b> is now connected to <b>NOLA SMS Pro</b></p>
    
    <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 32px;">
        <a href="{$dashboardUrl}" class="btn-primary">Open Dashboard</a>
        <div class="sender-toggle" onclick="toggleModal('sender-modal')">Request Sender ID</div>
    </div>

    <div onclick="toggleModal('how-modal')" class="tutorial-link">How it works & Credits</div>
HTML;

render_page('Success!', $body);