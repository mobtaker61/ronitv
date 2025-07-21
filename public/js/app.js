// Main Application JavaScript

class VideoApp {
    constructor() {
        this.searchInput = document.getElementById('searchInput');
        this.init();
    }

    init() {
        this.initSearch();
        this.initAnimations();
        this.initVideoPlayers();
        this.initTooltips();
        this.initLazyLoading();
    }

    // Search functionality
    initSearch() {
        if (this.searchInput) {
            this.searchInput.addEventListener('input', (e) => {
                this.performSearch(e.target.value);
            });

            // Add search button functionality
            const searchBtn = document.querySelector('.search-btn');
            if (searchBtn) {
                searchBtn.addEventListener('click', () => {
                    this.performSearch(this.searchInput.value);
                });
            }
        }
    }

    performSearch(query) {
        const searchTerm = query.toLowerCase();
        const cards = document.querySelectorAll('.video-card, .folder-card');

        cards.forEach(card => {
            const title = card.querySelector('.folder-name, .video-title, .folder-link span');
            if (title) {
                const titleText = title.textContent.toLowerCase();
                if (titleText.includes(searchTerm)) {
                    card.style.display = 'block';
                    card.classList.add('fade-in');
                } else {
                    card.style.display = 'none';
                }
            }
        });

        // Show/hide no results message
        this.toggleNoResultsMessage(cards, searchTerm);
    }

    toggleNoResultsMessage(cards, searchTerm) {
        const visibleCards = Array.from(cards).filter(card =>
            card.style.display !== 'none'
        );

        let noResultsMsg = document.querySelector('.no-results-message');

        if (searchTerm && visibleCards.length === 0) {
            if (!noResultsMsg) {
                noResultsMsg = document.createElement('div');
                noResultsMsg.className = 'no-results-message alert alert-info text-center mt-4';
                noResultsMsg.innerHTML = `
                    <i class="fas fa-search me-2"></i>
                    هیچ نتیجه‌ای برای "${searchTerm}" یافت نشد.
                `;

                const container = document.querySelector('.video-grid, .container');
                if (container) {
                    container.appendChild(noResultsMsg);
                }
            }
        } else if (noResultsMsg) {
            noResultsMsg.remove();
        }
    }

    // Animation initialization
    initAnimations() {
        // Add fade-in animation to cards
        const cards = document.querySelectorAll('.video-card, .folder-card');
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                }
            });
        }, { threshold: 0.1 });

        cards.forEach(card => {
            observer.observe(card);
        });

        // Add hover effects
        this.addHoverEffects();
    }

    addHoverEffects() {
        const cards = document.querySelectorAll('.video-card, .folder-card');

        cards.forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.style.transform = 'translateY(-5px) scale(1.02)';
            });

            card.addEventListener('mouseleave', () => {
                card.style.transform = 'translateY(0) scale(1)';
            });
        });
    }

    // Video player initialization
    initVideoPlayers() {
        const videos = document.querySelectorAll('video');

        videos.forEach(video => {
            // Add custom controls
            this.addCustomVideoControls(video);

            // Add keyboard shortcuts
            this.addVideoKeyboardShortcuts(video);

            // Add progress tracking
            this.addVideoProgressTracking(video);
        });
    }

    addCustomVideoControls(video) {
        // Add custom play/pause button
        const playBtn = video.parentElement.querySelector('.play-button');
        if (playBtn) {
            playBtn.addEventListener('click', (e) => {
                e.preventDefault();
                if (video.paused) {
                    video.play();
                } else {
                    video.pause();
                }
            });
        }

        // Update play button state
        video.addEventListener('play', () => {
            const playBtn = video.parentElement.querySelector('.play-button i');
            if (playBtn) {
                playBtn.className = 'fas fa-pause';
            }
        });

        video.addEventListener('pause', () => {
            const playBtn = video.parentElement.querySelector('.play-button i');
            if (playBtn) {
                playBtn.className = 'fas fa-play';
            }
        });
    }

    addVideoKeyboardShortcuts(video) {
        video.addEventListener('keydown', (e) => {
            switch(e.key) {
                case ' ':
                    e.preventDefault();
                    if (video.paused) video.play();
                    else video.pause();
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    video.currentTime += 10;
                    break;
                case 'ArrowLeft':
                    e.preventDefault();
                    video.currentTime -= 10;
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    video.volume = Math.min(1, video.volume + 0.1);
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    video.volume = Math.max(0, video.volume - 0.1);
                    break;
            }
        });
    }

    addVideoProgressTracking(video) {
        video.addEventListener('timeupdate', () => {
            const progress = (video.currentTime / video.duration) * 100;
            // You can add progress bar updates here
        });
    }

    // Tooltip initialization
    initTooltips() {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    // Lazy loading for images
    initLazyLoading() {
        const images = document.querySelectorAll('img[data-src]');

        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.classList.remove('lazy');
                    imageObserver.unobserve(img);
                }
            });
        });

        images.forEach(img => imageObserver.observe(img));
    }

    // Utility methods
    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} notification`;
        notification.innerHTML = `
            <i class="fas fa-${this.getNotificationIcon(type)} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        document.body.appendChild(notification);

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    getNotificationIcon(type) {
        const icons = {
            success: 'check-circle',
            error: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        return icons[type] || 'info-circle';
    }

    // File upload helpers
    initFileUpload() {
        const uploadAreas = document.querySelectorAll('.file-upload-area');

        uploadAreas.forEach(area => {
            area.addEventListener('dragover', (e) => {
                e.preventDefault();
                area.classList.add('dragover');
            });

            area.addEventListener('dragleave', () => {
                area.classList.remove('dragover');
            });

            area.addEventListener('drop', (e) => {
                e.preventDefault();
                area.classList.remove('dragover');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    this.handleFileDrop(files, area);
                }
            });
        });
    }

    handleFileDrop(files, area) {
        const fileInput = area.parentElement.querySelector('input[type="file"]');
        if (fileInput) {
            fileInput.files = files;
            this.updateFileDisplay(files[0], area);
        }
    }

    updateFileDisplay(file, area) {
        area.innerHTML = `
            <div class="upload-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <p class="text-white mb-2">فایل انتخاب شده:</p>
            <p class="text-success fw-bold">${file.name}</p>
            <small class="text-muted">${this.formatFileSize(file.size)}</small>
        `;
    }

    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
}

// Initialize app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.videoApp = new VideoApp();

    // Add global error handler
    window.addEventListener('error', (e) => {
        console.error('Global error:', e.error);
        if (window.videoApp) {
            window.videoApp.showNotification('خطایی رخ داده است', 'error');
        }
    });
});

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = VideoApp;
}
