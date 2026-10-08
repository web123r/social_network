document.addEventListener('DOMContentLoaded', () => {
    // Like button toggle
    const likeBtns = document.querySelectorAll('.like-btn');
    
    likeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            this.classList.toggle('active');
            const icon = this.querySelector('i');
            
            if (this.classList.contains('active')) {
                icon.classList.remove('fa-regular');
                icon.classList.add('fa-solid');
                icon.style.color = '#1877f2'; // fb blue
                this.style.color = '#1877f2';
            } else {
                icon.classList.remove('fa-solid');
                icon.classList.add('fa-regular');
                icon.style.color = '';
                this.style.color = '';
            }
        });
    });

    // Simple interaction for top nav icons
    const navIcons = document.querySelectorAll('.nav-center .nav-icon');
    navIcons.forEach(icon => {
        icon.addEventListener('click', function(e) {
            if (this.getAttribute('href') === '#') {
                e.preventDefault();
                navIcons.forEach(i => i.classList.remove('active'));
                this.classList.add('active');
            }
        });
    });

    
});
