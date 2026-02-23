/**
 * Semitexa Async Resource Manager
 * Handles SSE for async handler updates
 */
class SemitexaAsyncResourceManager {
    constructor() {
        this.sessionId = this.getOrCreateSessionId();
        this.eventSource = null;
        this.connect();
    }

    getOrCreateSessionId() {
        let id = sessionStorage.getItem('semitexa_sse_session');
        if (!id) {
            id = 'sse_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('semitexa_sse_session', id);
        }
        return id;
    }

    connect() {
        if (!this.supportsSSE()) {
            console.warn('SSE not supported');
            return;
        }

        this.eventSource = new EventSource(`/__semitexa_sse?session_id=${this.sessionId}`);
        
        this.eventSource.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                this.handleMessage(data);
            } catch (e) {
                console.error('Failed to parse SSE message:', e);
            }
        };

        this.eventSource.onerror = (event) => {
            console.error('SSE error:', event);
            this.reconnect();
        };

        this.eventSource.onopen = () => {
            console.log('SSE connected');
        };
    }

    supportsSSE() {
        return typeof EventSource !== 'undefined';
    }

    reconnect() {
        if (this.eventSource) {
            this.eventSource.close();
        }
        setTimeout(() => {
            this.connect();
        }, 5000);
    }

    handleMessage(data) {
        if (!data || !data.handler) {
            return;
        }

        const placeholder = document.querySelector(`[data-async-resource="${data.handler}"]`);
        
        if (placeholder) {
            placeholder.innerHTML = data.html || '';
            placeholder.classList.remove('loading');
            placeholder.removeAttribute('data-placeholder');
            
            placeholder.dispatchEvent(new CustomEvent('resource-loaded', { 
                detail: data 
            }));
        }

        window.dispatchEvent(new CustomEvent('async-resource-loaded', {
            detail: data
        }));
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    }
}

if (typeof window !== 'undefined') {
    window.SemitexaAsyncResourceManager = SemitexaAsyncResourceManager;
    
    document.addEventListener('DOMContentLoaded', () => {
        if (document.querySelector('[data-async-resource]')) {
            new SemitexaAsyncResourceManager();
        }
    });
}
