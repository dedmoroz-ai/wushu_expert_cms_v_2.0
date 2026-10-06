
<style>
    /* Полностью изолированные стили, не зависящие от Filament */
    .qr-modal-isolated {
        all: initial;
        display: block;
        font-family: system-ui, -apple-system, sans-serif;
    }
    
    .qr-modal-isolated * {
        all: unset;
        display: revert;
        box-sizing: border-box;
    }
    
    .qr-modal-isolated .qr-label {
        display: block;
        font-size: 0.875rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #374151 !important;
    }
    
    .qr-modal-isolated .qr-url-box {
        display: flex;
        gap: 0.5rem;
        width: 100%;
        margin-bottom: 1rem;
    }
    
    .qr-modal-isolated .qr-url-text {
        flex: 1;
        padding: 0.5rem 0.75rem;
        background-color: #f9fafb;
        border: 1px solid #d1d5db;
        border-radius: 0.375rem;
        font-size: 0.875rem;
        color: #111827 !important;
        word-break: break-all;
        min-height: 2.5rem;
        display: flex;
        align-items: center;
    }
    
    .qr-modal-isolated .qr-button {
        padding: 0.5rem 1rem;
        border-radius: 0.375rem;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        border: none;
        white-space: nowrap;
    }
    
    .qr-modal-isolated .qr-button-blue {
        background-color: #0A92BA;
        color: white !important;
    }
    
    .qr-modal-isolated .qr-button-blue:hover {
        background-color: #0b7ea4;
    }
    
    .qr-modal-isolated .qr-button-green {
        background-color: #229954;
        color: white !important;
        width: 100%;
        text-align: center;
    }
    
    .qr-modal-isolated .qr-button-green:hover {
        background-color: #1d7f46;
    }
    
    .qr-modal-isolated .qr-description {
        font-size: 0.875rem;
        color: #4b5563 !important;
        margin-bottom: 1rem;
    }
    
    .qr-modal-isolated .qr-code-container {
        background: white;
        padding: 1rem;
        border-radius: 0.5rem;
        border: 2px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1rem;
    }
</style>

<div class="qr-modal-isolated">
    <div class="qr-description">
        Отсканируйте QR-код или перейдите по ссылке для доступа к публичной странице результатов соревнования.
    </div>
    
    <div style="display: flex; flex-direction: column; align-items: center; gap: 1rem;">
        {{-- QR-код --}}
        <div class="qr-code-container">
            @if($qrCodeUrl)
                <img 
                    src="{{ $qrCodeUrl }}" 
                    alt="QR Code" 
                    style="width: 256px; height: 256px;"
                    onerror="this.onerror=null; this.src='https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent('{{ $publicUrl }}');"
                >
            @else
                <div style="text-align: center; color: #4b5563;">
                    <p>Ошибка генерации QR-кода</p>
                </div>
            @endif
        </div>
        
        {{-- Ссылка --}}
        <div style="width: 100%;">
            <label class="qr-label">Ссылка на публичную страницу:</label>
            <div class="qr-url-box">
                <div 
                    id="public-url-display" 
                    class="qr-url-text"
                >
                    {{ $publicUrl }}
                </div>
                <button 
                    onclick="copyToClipboard()"
                    class="qr-button qr-button-blue"
                >
                    Копировать
                </button>
            </div>
        </div>
        
        {{-- Кнопка открыть в новой вкладке --}}
        <div style="width: 100%;">
            <a 
                href="{{ $publicUrl }}" 
                target="_blank"
                class="qr-button qr-button-green"
            >
                Открыть публичную страницу
            </a>
        </div>
    </div>
</div>

<script>
// Агрессивное исправление цветов с использованием MutationObserver
(function() {
    function forceStyles() {
        const input = document.getElementById('public-url-input');
        const label = document.querySelector('label[for="public-url-input"]') || 
                     (input ? input.closest('.w-full').querySelector('label') : null);
        
        if (input) {
            // Удаляем все классы, которые могут влиять на цвет
            input.classList.remove('fi-input');
            input.classList.remove('dark:text-white');
            
            // Принудительно устанавливаем стили через setAttribute
            input.setAttribute('style', 
                'color: #111827 !important; ' +
                '-webkit-text-fill-color: #111827 !important; ' +
                'caret-color: #111827 !important; ' +
                'background-color: #f9fafb !important; ' +
                'opacity: 1 !important;'
            );
            
            // Также через style напрямую
            input.style.cssText = 
                'color: #111827 !important; ' +
                '-webkit-text-fill-color: #111827 !important; ' +
                'caret-color: #111827 !important; ' +
                'background-color: #f9fafb !important; ' +
                'opacity: 1 !important;';
            
            // Создаем стиль-элемент для максимальной специфичности
            let styleEl = document.getElementById('qr-input-fix-style');
            if (!styleEl) {
                styleEl = document.createElement('style');
                styleEl.id = 'qr-input-fix-style';
                document.head.appendChild(styleEl);
            }
            styleEl.textContent = `
                #public-url-input,
                input#public-url-input,
                input[id="public-url-input"] {
                    color: #111827 !important;
                    -webkit-text-fill-color: #111827 !important;
                    caret-color: #111827 !important;
                    background-color: #f9fafb !important;
                    opacity: 1 !important;
                }
            `;
        }
        
        if (label) {
            label.setAttribute('style', 'color: #374151 !important; -webkit-text-fill-color: #374151 !important;');
            label.style.cssText = 'color: #374151 !important; -webkit-text-fill-color: #374151 !important;';
        }
    }
    
    // Запускаем сразу
    forceStyles();
    
    // После загрузки DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', forceStyles);
    } else {
        forceStyles();
    }
    
    // Множественные попытки с задержками
    [50, 100, 200, 500, 1000].forEach(delay => {
        setTimeout(forceStyles, delay);
    });
    
    // Используем MutationObserver для отслеживания изменений
    const observer = new MutationObserver(function(mutations) {
        forceStyles();
    });
    
    // Начинаем наблюдение после небольшой задержки
    setTimeout(() => {
        const input = document.getElementById('public-url-input');
        if (input) {
            observer.observe(input, {
                attributes: true,
                attributeFilter: ['style', 'class'],
                childList: false,
                subtree: false
            });
            
            // Также наблюдаем за родительским элементом
            const parent = input.closest('.fi-modal-content') || input.closest('[x-data]');
            if (parent) {
                observer.observe(parent, {
                    attributes: true,
                    attributeFilter: ['class', 'style'],
                    childList: true,
                    subtree: true
                });
            }
        }
    }, 100);
    
    // Используем requestAnimationFrame для постоянного обновления
    function continuousFix() {
        forceStyles();
        requestAnimationFrame(continuousFix);
    }
    // Запускаем на 2 секунды после загрузки
    setTimeout(() => {
        let count = 0;
        const interval = setInterval(() => {
            forceStyles();
            count++;
            if (count > 20) clearInterval(interval); // Останавливаем через 2 секунды
        }, 100);
    }, 100);
})();

function copyToClipboard() {
    const input = document.getElementById('public-url-input');
    const display = document.getElementById('public-url-display');
    const textToCopy = input ? input.value : (display ? display.textContent.trim() : '{{ $publicUrl }}');
    
    // Используем современный API
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(textToCopy).then(() => {
            alert('Ссылка скопирована в буфер обмена!');
        }).catch(() => {
            // Fallback для старых браузеров
            fallbackCopy(textToCopy);
        });
    } else {
        fallbackCopy(textToCopy);
    }
}

function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    textarea.setSelectionRange(0, 99999);
    
    try {
        document.execCommand('copy');
        alert('Ссылка скопирована в буфер обмена!');
    } catch (err) {
        alert('Не удалось скопировать. Скопируйте вручную: ' + text);
    }
    
    document.body.removeChild(textarea);
}
</script>
