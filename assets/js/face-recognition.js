/**
 * PCU Face Recognition System
 * Uses face-api.js (TensorFlow.js) with pre-trained models
 * 
 * Security: Face descriptors are extracted client-side, sent to server
 * for encrypted storage. Matching happens client-side with decrypted
 * descriptors fetched over authenticated API.
 * 
 * @version 1.0.0
 */

class FaceRecognitionSystem {
    constructor(options = {}) {
        this.modelPath = options.modelPath || '../assets/models';
        this.matchThreshold = options.matchThreshold || 0.6;
        this.minConfidence = options.minConfidence || 0.5;
        this.csrfToken = options.csrfToken || '';
        this.modelsLoaded = false;
        this.isProcessing = false;
        this.videoElement = null;
        this.canvasElement = null;
        this.stream = null;
        this.knownDescriptors = []; // [{userId, name, studentId, descriptor}]
        this.onDetection = options.onDetection || null;
        this.onError = options.onError || null;
        this.onStatusChange = options.onStatusChange || null;
        this.detectionInterval = null;
        this.detectionIntervalMs = options.detectionIntervalMs || 250;
        // SSD input size: lower = faster, higher = more accurate
        // 128/160/224/320/416/512/608 — 224 is a good speed/accuracy tradeoff
        this.ssdInputSize = options.ssdInputSize || 224;
        // Cached SSD options (avoid recreating every frame)
        this._ssdOptions = null;
        this._isWarmedUp = false;
        // Selected camera device ID (null = browser default)
        this.selectedDeviceId = null;
    }

    /**
     * Load face-api.js models (pre-trained)
     * Models: SSD MobileNet v1 (detection), Face Landmark 68 (alignment), Face Recognition (128-dim descriptor)
     */
    async loadModels() {
        this._setStatus('loading', 'Loading face recognition models...');
        
        try {
            await Promise.all([
                faceapi.nets.ssdMobilenetv1.loadFromUri(this.modelPath),
                faceapi.nets.faceLandmark68Net.loadFromUri(this.modelPath),
                faceapi.nets.faceRecognitionNet.loadFromUri(this.modelPath),
            ]);
            
            this.modelsLoaded = true;
            // Pre-create SSD options for reuse (avoids object creation every frame)
            this._ssdOptions = new faceapi.SsdMobilenetv1Options({
                inputSize: this.ssdInputSize,
                minConfidence: this.minConfidence,
                maxResults: 1
            });
            this._setStatus('ready', 'Face recognition models loaded successfully');
            return true;
        } catch (error) {
            this._handleError('Failed to load face recognition models. Ensure model files exist in: ' + this.modelPath, error);
            return false;
        }
    }

    /**
     * Return list of available video input devices.
     * Note: Device labels are only populated after getUserMedia permission is granted.
     * @returns {Promise<Array<{deviceId: string, label: string}>>}
     */
    async getAvailableCameras() {
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            return devices
                .filter(device => device.kind === 'videoinput')
                .map((device, index) => ({
                    deviceId: device.deviceId,
                    label: device.label || `Camera ${index + 1}`
                }));
        } catch (err) {
            return [];
        }
    }

    /**
     * Start webcam video stream
     * @param {HTMLVideoElement} videoEl - Video element for camera preview
     * @param {HTMLCanvasElement} canvasEl - Canvas for drawing detection boxes
     * @param {string|null} deviceId - Specific camera deviceId, or null for default
     */
    async startCamera(videoEl, canvasEl, deviceId = null) {
        this.videoElement = videoEl;
        this.canvasElement = canvasEl;
        if (deviceId !== null) this.selectedDeviceId = deviceId;

        try {
            let videoConstraints;
            if (this.selectedDeviceId) {
                videoConstraints = { deviceId: { exact: this.selectedDeviceId } };
            } else {
                videoConstraints = {
                    width: { ideal: 480 },
                    height: { ideal: 360 },
                    facingMode: 'user',
                    frameRate: { ideal: 15, max: 30 }
                };
            }

            this.stream = await navigator.mediaDevices.getUserMedia({
                video: videoConstraints,
                audio: false
            });

            this.videoElement.srcObject = this.stream;
            await this.videoElement.play();

            // Warm up the model in background so camera preview appears immediately.
            this._warmUpDetector();

            const displaySize = {
                width: this.videoElement.videoWidth,
                height: this.videoElement.videoHeight
            };
            faceapi.matchDimensions(this.canvasElement, displaySize);

            this._setStatus('camera_active', 'Camera active - ready for face detection');
            return true;
        } catch (error) {
            // If specific camera selection fails, retry once with default camera.
            // This prevents Start from appearing broken when the selected device
            // is disconnected, locked by another app, or returns stale IDs.
            if (this.selectedDeviceId) {
                try {
                    this.selectedDeviceId = null;
                    this.stream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            width: { ideal: 480 },
                            height: { ideal: 360 },
                            facingMode: 'user',
                            frameRate: { ideal: 15, max: 30 }
                        },
                        audio: false
                    });

                    this.videoElement.srcObject = this.stream;
                    await this.videoElement.play();
                    this._warmUpDetector();

                    const displaySize = {
                        width: this.videoElement.videoWidth,
                        height: this.videoElement.videoHeight
                    };
                    faceapi.matchDimensions(this.canvasElement, displaySize);

                    this._setStatus('camera_active', 'Camera active (fallback default camera)');
                    return true;
                } catch (fallbackError) {
                    error = fallbackError;
                }
            }

            if (error.name === 'NotAllowedError') {
                this._handleError('Camera access denied. Please allow camera permission.', error);
            } else if (error.name === 'NotFoundError') {
                this._handleError('No camera found. Please connect a webcam.', error);
            } else {
                this._handleError('Failed to start camera: ' + error.message, error);
            }
            return false;
        }
    }

    /**
     * Run one throwaway inference after camera starts to warm up TF.js graph/JIT.
     * This removes the multi-second delay on the very first face scan.
     */
    async _warmUpDetector() {
        if (this._isWarmedUp || !this.videoElement || !this.modelsLoaded) return;
        try {
            const options = this._ssdOptions || new faceapi.SsdMobilenetv1Options({
                inputSize: this.ssdInputSize,
                minConfidence: this.minConfidence,
                maxResults: 1
            });
            await faceapi
                .detectSingleFace(this.videoElement, options)
                .withFaceLandmarks()
                .withFaceDescriptor();
            this._isWarmedUp = true;
        } catch (e) {
            // Non-fatal: warmup failure should not block camera usage
        }
    }

    /**
     * Switch to a different camera device.
     *
     * Chrome on Windows can occasionally return a stream from the previous camera
     * even when a different deviceId was requested. This method verifies the
     * actual active device from track settings and retries before reporting success.
     *
     * @param {string} newDeviceId - deviceId of the camera to switch to
     * @returns {Promise<boolean>} true if switched successfully
     */
    async switchCamera(newDeviceId) {
        if (!this.videoElement || !this.canvasElement || !newDeviceId) return false;

        this._continuousRunning = false;
        if (this.detectionInterval) {
            clearTimeout(this.detectionInterval);
            this.detectionInterval = null;
        }

        if (this.stream) {
            this.stream.getTracks().forEach(track => {
                track.enabled = false;
                track.stop();
            });
            this.stream = null;
        }

        const parent = this.videoElement.parentNode;
        const oldVideo = this.videoElement;
        const marker = oldVideo.nextSibling;

        const newVideo = document.createElement('video');
        newVideo.id = oldVideo.id;
        newVideo.className = oldVideo.className;
        newVideo.autoplay = true;
        newVideo.muted = true;
        newVideo.playsInline = true;
        newVideo.setAttribute('playsinline', '');

        oldVideo.pause();
        oldVideo.srcObject = null;
        oldVideo.remove();
        if (marker) {
            parent.insertBefore(newVideo, marker);
        } else {
            parent.appendChild(newVideo);
        }
        this.videoElement = newVideo;

        const maxAttempts = 3;
        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                const candidateStream = await navigator.mediaDevices.getUserMedia({
                    video: { deviceId: { exact: newDeviceId } },
                    audio: false
                });

                const track = candidateStream.getVideoTracks()[0];
                const actualId = track?.getSettings?.().deviceId || '';

                if (actualId && actualId !== newDeviceId) {
                    candidateStream.getTracks().forEach(t => t.stop());
                    await new Promise(resolve => setTimeout(resolve, 250 * attempt));
                    continue;
                }

                this.stream = candidateStream;
                this.selectedDeviceId = newDeviceId;
                this.videoElement.srcObject = this.stream;
                await this.videoElement.play();

                const displaySize = {
                    width: this.videoElement.videoWidth,
                    height: this.videoElement.videoHeight
                };
                faceapi.matchDimensions(this.canvasElement, displaySize);

                this._setStatus('camera_active', 'Camera switched successfully');
                return true;
            } catch (error) {
                if (attempt === maxAttempts) {
                    this._handleError('Failed to switch camera: ' + error.message, error);
                    return false;
                }
                await new Promise(resolve => setTimeout(resolve, 250 * attempt));
            }
        }

        this._handleError('Failed to switch camera: browser returned a different device than requested.');
        return false;
    }

    /**
     * Stop webcam stream and clean up
     */
    stopCamera() {
        this._continuousRunning = false;
        if (this.detectionInterval) {
            clearTimeout(this.detectionInterval);
            this.detectionInterval = null;
        }

        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.stream = null;
        }

        if (this.videoElement) {
            // Stop playback and clear the source — do NOT call video.load() here.
            // load() triggers an async internal reset that races with any subsequent
            // srcObject assignment in startCamera(), causing the browser to reuse
            // the old device instead of opening the newly requested one.
            this.videoElement.pause();
            this.videoElement.srcObject = null;
        }

        if (this.canvasElement) {
            const ctx = this.canvasElement.getContext('2d');
            ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);
        }

        this._setStatus('stopped', 'Camera stopped');
    }

    /**
     * Detect a single face from the current video frame
     * Used during face registration (admin side)
     * @returns {Object|null} {detection, descriptor, landmarks} or null
     */
    async detectSingleFace() {
        if (!this.modelsLoaded || !this.videoElement) {
            this._handleError('Models not loaded or camera not started');
            return null;
        }

        try {
            const options = this._ssdOptions || new faceapi.SsdMobilenetv1Options({
                minConfidence: this.minConfidence,
                maxResults: 1
            });

            const detection = await faceapi
                .detectSingleFace(this.videoElement, options)
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (!detection) {
                return null;
            }

            // Draw detection on canvas
            this._drawDetection(detection);

            return {
                descriptor: Array.from(detection.descriptor),
                score: detection.detection.score,
                box: detection.detection.box,
                landmarks: detection.landmarks
            };
        } catch (error) {
            this._handleError('Face detection error', error);
            return null;
        }
    }

    /**
     * Start continuous face detection for gate monitoring
     * Matches detected faces against known descriptors
     */
    startContinuousDetection() {
        if (this.detectionInterval) {
            clearInterval(this.detectionInterval);
        }

        this._setStatus('detecting', 'Continuous face detection active');

        // Use a self-scheduling loop instead of setInterval for better performance.
        // This ensures we don't queue up detections if one takes longer than the interval.
        let running = true;
        this._continuousRunning = true;

        const detectLoop = async () => {
            if (!this._continuousRunning) return;

            if (!this.isProcessing) {
                this.isProcessing = true;
                try {
                    const detection = await this.detectSingleFace();

                    if (detection && detection.descriptor) {
                        const match = this._findBestMatch(detection.descriptor);

                        if (this.onDetection) {
                            this.onDetection({
                                matched: match !== null,
                                match: match,
                                confidence: detection.score,
                                timestamp: new Date().toISOString()
                            });
                        }
                    }
                } catch (error) {
                    console.error('Detection cycle error:', error);
                } finally {
                    this.isProcessing = false;
                }
            }

            // Schedule next detection
            if (this._continuousRunning) {
                this.detectionInterval = setTimeout(detectLoop, this.detectionIntervalMs);
            }
        };

        detectLoop();
    }

    /**
     * Stop continuous detection
     */
    stopContinuousDetection() {
        this._continuousRunning = false;
        if (this.detectionInterval) {
            clearTimeout(this.detectionInterval);
            this.detectionInterval = null;
        }
        this._setStatus('paused', 'Detection paused');
    }

    /**
     * Load known face descriptors from server
     * Descriptors are decrypted server-side and sent over HTTPS
     * @param {string} apiUrl - URL to fetch descriptors
     */
    async loadKnownFaces(apiUrl) {
        this._setStatus('loading_faces', 'Loading registered faces...');

        try {
            const response = await fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`Server returned ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to load face descriptors');
            }

            this.knownDescriptors = (data.descriptors || []).map(d => ({
                userId: d.user_id,
                name: d.name,
                studentId: d.student_id,
                email: d.email,
                profilePicture: d.profile_picture || null,
                descriptor: new Float32Array(d.descriptor)
            }));

            this._setStatus('faces_loaded', `Loaded ${this.knownDescriptors.length} registered faces`);
            return this.knownDescriptors.length;
        } catch (error) {
            this._handleError('Failed to load known faces', error);
            return 0;
        }
    }

    /**
     * Register a face descriptor to the server
     * @param {number} studentId - Student user ID
     * @param {Array} descriptor - 128-dimensional descriptor array
     * @param {string} label - Descriptor label (front, left, right)
     * @param {number} qualityScore - Detection confidence score
     * @param {string} apiUrl - Registration API URL
     */
    async registerFace(studentId, descriptor, label, qualityScore, apiUrl) {
        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    student_id: studentId,
                    descriptor: descriptor,
                    label: label,
                    quality_score: qualityScore
                })
            });

            if (!response.ok) {
                throw new Error(`Server returned ${response.status}`);
            }

            const data = await response.json();
            return data;
        } catch (error) {
            this._handleError('Failed to register face', error);
            return { success: false, error: error.message };
        }
    }

    /**
     * Log a face-based entry at the gate
     * @param {number} userId - Matched user ID
     * @param {number} confidence - Match confidence score
     * @param {string} apiUrl - Entry logging API URL
     */
    async logFaceEntry(userId, confidence, apiUrl) {
        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    user_id: userId,
                    confidence_score: confidence,
                    match_threshold: this.matchThreshold
                })
            });

            if (!response.ok) {
                throw new Error(`Server returned ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            this._handleError('Failed to log face entry', error);
            return { success: false, error: error.message };
        }
    }

    /**
     * Delete all face descriptors for a student
     * @param {number} studentId - Student user ID
     * @param {string} apiUrl - Delete API URL
     */
    async deleteFace(studentId, apiUrl) {
        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    student_id: studentId
                })
            });

            if (!response.ok) {
                throw new Error(`Server returned ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            this._handleError('Failed to delete face data', error);
            return { success: false, error: error.message };
        }
    }

    // ========================================
    // PRIVATE METHODS
    // ========================================

    /**
     * Find the best matching known face for a given descriptor
     * Uses Euclidean distance - lower is better
     * @param {Float32Array|Array} queryDescriptor
     * @returns {Object|null} Best match or null if no match
     */
    _findBestMatch(queryDescriptor) {
        if (this.knownDescriptors.length === 0) return null;

        const query = queryDescriptor instanceof Float32Array 
            ? queryDescriptor 
            : new Float32Array(queryDescriptor);

        let bestMatch = null;
        let bestDistance = this.matchThreshold; // Start at threshold for early exit

        for (const known of this.knownDescriptors) {
            const distance = faceapi.euclideanDistance(query, known.descriptor);

            if (distance < bestDistance) {
                bestDistance = distance;
                bestMatch = known;
                // Early exit if we find a very strong match (distance < 0.35)
                if (distance < 0.35) break;
            }
        }

        // Match found (bestMatch is only set if distance was below threshold)
        if (bestMatch) {
            return {
                ...bestMatch,
                distance: bestDistance,
                confidence: Math.max(0, 1 - bestDistance).toFixed(4)
            };
        }

        return null;
    }

    /**
     * Draw face detection box and landmarks on canvas
     */
    _drawDetection(detection) {
        if (!this.canvasElement) return;

        const displaySize = {
            width: this.videoElement.videoWidth,
            height: this.videoElement.videoHeight
        };

        const resized = faceapi.resizeResults(detection, displaySize);
        const ctx = this.canvasElement.getContext('2d');
        ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);

        // Draw bounding box
        const box = resized.detection.box;
        ctx.strokeStyle = '#00ff00';
        ctx.lineWidth = 3;
        ctx.strokeRect(box.x, box.y, box.width, box.height);

        // Draw confidence score
        ctx.fillStyle = '#00ff00';
        ctx.font = 'bold 14px Arial';
        const score = (detection.detection.score * 100).toFixed(1);
        ctx.fillText(`${score}%`, box.x, box.y - 8);
    }

    /**
     * Set system status and notify callback
     */
    _setStatus(status, message) {
        console.log(`[FaceRec] ${status}: ${message}`);
        if (this.onStatusChange) {
            this.onStatusChange(status, message);
        }
    }

    /**
     * Handle errors and notify callback
     */
    _handleError(message, error = null) {
        console.error(`[FaceRec Error] ${message}`, error || '');
        if (this.onError) {
            this.onError(message, error);
        }
    }
}

// Export for module systems, also available as global
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FaceRecognitionSystem;
}
