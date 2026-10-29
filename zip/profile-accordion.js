function initAccordions() {
    const headers = document.querySelectorAll('.accordion-header');

    headers.forEach(header => {
        header.addEventListener('click', (e) => {
            e.stopPropagation();
            const content = header.nextElementSibling;
            const isCurrentlyOpen = content && content.classList.contains('open');
            
            // Закрываем все гармошки
            document.querySelectorAll('.accordion-content').forEach(c => c.classList.remove('open'));
            document.querySelectorAll('.accordion-header .toggle-icon').forEach(icon => {
                icon.classList.remove('fa-minus-circle');
                icon.classList.add('fa-plus-circle');
            });
            
            // Если текущая была закрыта, открываем её
            if (!isCurrentlyOpen && content) {
                content.classList.add('open');
                const icon = header.querySelector('.toggle-icon');
                icon.classList.remove('fa-plus-circle');
                icon.classList.add('fa-minus-circle');
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initAccordions);
