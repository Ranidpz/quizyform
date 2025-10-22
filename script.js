document.addEventListener('DOMContentLoaded', function() {
    console.log("טופס קוויזי נטען - גרסה 20250703094001");
    
    // Form elements
    const form = document.getElementById('subscriptionForm');
    const submitBtn = document.getElementById('submitBtn');
    const storageOptions = document.querySelectorAll('.storage-option');
    const packageButtons = document.querySelectorAll('.select-package-btn');
    const packageRadios = document.querySelectorAll('input[name="package"]');
    
    // מעקב אחר בחירת חבילה
    let packageSelected = false;
    
    // הסרת כל הסימונים בטעינת הדף
    packageRadios.forEach(radio => {
        radio.checked = false;
        const storageOption = radio.closest('.storage-option');
        if (storageOption) {
            storageOption.classList.remove('active');
        }
    });
    
    packageButtons.forEach(btn => {
        btn.classList.remove('selected');
        btn.textContent = 'בחירה';
    });
    
    // Initialize the form
    initializeForm();
    
    // Add event listeners
    form.addEventListener('submit', handleSubmit);
    
    // Add click event to all select buttons
    packageButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const packageId = this.getAttribute('data-package');
            const radioInput = document.getElementById(packageId);
            
            // הסרת בחירה מכל החבילות
            packageRadios.forEach(radio => {
                radio.checked = false;
                const storageOption = radio.closest('.storage-option');
                if (storageOption) {
                    storageOption.classList.remove('active');
                }
            });
            
            packageButtons.forEach(btn => {
                btn.classList.remove('selected');
                btn.textContent = 'בחירה';
            });
            
            // סימון החבילה הנבחרת
            radioInput.checked = true;
            const selectedOption = radioInput.closest('.storage-option');
            if (selectedOption) {
                selectedOption.classList.add('active');
            }
            
            // עדכון כפתור הבחירה
            this.classList.add('selected');
            this.textContent = 'נבחר';
            
            // עדכון סטטוס בחירת חבילה
            packageSelected = true;
            
            // הסרת הודעות שגיאה אם קיימות
            const existingError = document.querySelector('.package-error-message');
            if (existingError) {
                existingError.remove();
            }
            
            // הסרת הודעות שגיאה מהמיכל
            const messageContainer = document.getElementById('messageContainer');
            if (messageContainer) {
                messageContainer.className = 'message-container';
                messageContainer.textContent = '';
            }
            
            // אפקט חזותי נוסף להדגשת החבילה הנבחרת
            if (selectedOption) {
                // הוספת אפקט הבהוב קל לחבילה הנבחרת
                selectedOption.style.transition = 'all 0.3s ease';
                selectedOption.style.transform = 'scale(1.05)';
                
                setTimeout(() => {
                    selectedOption.style.transform = 'translateY(-8px)';
                }, 300);
                
                // הוספת אפקט הבהוב לכפתור הנבחר
                this.style.transition = 'all 0.3s ease';
                this.style.transform = 'scale(1.05)';
                setTimeout(() => {
                    this.style.transform = '';
                }, 300);
            }
            
            console.log(`נבחרה חבילה: ${packageId}`);

            // Show and update features section
            showFeaturesSection(packageId);
        });
    });
    
    // Add click event to package labels for better UX
    document.querySelectorAll('.package-label').forEach(label => {
        label.addEventListener('click', function(e) {
            // Prevent default only if the click was directly on the label (not on the button)
            if (e.target === this || !e.target.classList.contains('select-package-btn')) {
                e.preventDefault();
                // Find the button inside this label and trigger its click event
                const button = this.querySelector('.select-package-btn');
                if (button) {
                    button.click();
                }
            }
        });
    });
    
    /**
     * Initialize the form and set default values
     */
    function initializeForm() {
        console.log("מאתחל את הטופס...");
        
        // Set premium package as default
        const premiumRadio = document.getElementById('premium');
        if (premiumRadio) {
            premiumRadio.checked = true;
            const premiumOption = premiumRadio.closest('.storage-option');
            if (premiumOption) {
                premiumOption.classList.add('active');
            }
            
            // Update the premium button
            const premiumButton = document.querySelector('.select-package-btn[data-package="premium"]');
            if (premiumButton) {
                premiumButton.classList.add('selected');
                premiumButton.textContent = 'נבחר';
            }
            
            // Set packageSelected to true since we have a default selection
            packageSelected = true;
            
            console.log("חבילת פרימיום נבחרה כברירת מחדל");
        }
        
        // עדכון ערכי המחירים עם מע"מ של 18%
        const packagePrices = {
            'basic': '35',
            'standard': '71',
            'premium': '118',
            'pro': '159',
            'ultimate': '189'
        };
        
        // עדכון ערכי data-price בכל חבילה
        Object.keys(packagePrices).forEach(packageId => {
            const radio = document.getElementById(packageId);
            if (radio) {
                radio.setAttribute('data-price', packagePrices[packageId]);
            }
        });
        
        // Add input validation listeners
        const requiredInputs = form.querySelectorAll('input[required]');
        requiredInputs.forEach(input => {
            input.addEventListener('blur', validateInput);
            input.addEventListener('input', function() {
                if (this.classList.contains('error')) {
                    validateInput.call(this);
                }
            });
        });
        
        // Add special validation for email and phone
        const emailInput = document.getElementById('email');
        if (emailInput) {
            emailInput.addEventListener('blur', validateEmail);
            emailInput.addEventListener('input', function() {
                if (this.classList.contains('error')) {
                    validateEmail.call(this);
                }
            });
        }
        
        const phoneInput = document.getElementById('phone');
        if (phoneInput) {
            phoneInput.addEventListener('blur', validatePhone);
            phoneInput.addEventListener('input', function() {
                if (this.classList.contains('error')) {
                    validatePhone.call(this);
                }
            });
        }
        
        // Add click event to radio buttons for direct selection
        packageRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                updateSelectedPackage(this.id);
            });
        });
        
        // Apply initial visual highlight to the selected package
        highlightSelectedPackage();
    }
    
    /**
     * Update the selected package
     * @param {string} packageId - ID of the selected package
     */
    function updateSelectedPackage(packageId) {
        // Find the radio button and select it
        const radioButton = document.getElementById(packageId);
        if (radioButton) {
            // Reset all package selections
            packageButtons.forEach(btn => {
                btn.textContent = 'בחירה';
                btn.classList.remove('selected');
            });
            
            storageOptions.forEach(option => {
                option.classList.remove('active');
            });
            
            // Set the new selection
            radioButton.checked = true;
            packageSelected = true;
            
            // Update button text and style
            const selectedButton = document.querySelector(`.select-package-btn[data-package="${packageId}"]`);
            if (selectedButton) {
                selectedButton.textContent = 'נבחר';
                selectedButton.classList.add('selected');
            }
            
            // Add active class to the parent option
            const parentOption = radioButton.closest('.storage-option');
            if (parentOption) {
                parentOption.classList.add('active');
                
                // אפקט חזותי נוסף להדגשת החבילה הנבחרת
                parentOption.style.transition = 'all 0.3s ease';
                parentOption.style.transform = 'scale(1.05)';
                
                setTimeout(() => {
                    parentOption.style.transform = 'translateY(-8px)';
                }, 300);
                
                // Scroll to make the selected package visible if needed
                setTimeout(() => {
                    parentOption.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 100);
            }
            
            // הסרת הודעות שגיאה אם קיימות
            const existingError = document.querySelector('.package-error-message');
            if (existingError) {
                existingError.remove();
            }
            
            console.log(`עדכון חבילה נבחרת: ${packageId}`);
        }
    }
    
    /**
     * Apply visual highlight to the currently selected package
     */
    function highlightSelectedPackage() {
        const selectedRadio = document.querySelector('input[name="package"]:checked');
        if (selectedRadio) {
            const packageId = selectedRadio.id;
            const selectedButton = document.querySelector(`.select-package-btn[data-package="${packageId}"]`);
            if (selectedButton) {
                selectedButton.textContent = 'נבחר';
                selectedButton.classList.add('selected');
            }
            
            const parentOption = selectedRadio.closest('.storage-option');
            if (parentOption) {
                parentOption.classList.add('active');
                
                // אפקט חזותי נוסף להדגשת החבילה הנבחרת
                parentOption.style.transition = 'all 0.3s ease';
                parentOption.style.transform = 'translateY(-8px)';
            }
            
            console.log(`חבילה נבחרת מודגשת: ${packageId}`);
        }
    }
    
    /**
     * Validate a required input
     */
    function validateInput() {
        if (!this.value.trim()) {
            this.classList.add('error');
            return false;
        } else {
            this.classList.remove('error');
            return true;
        }
    }
    
    /**
     * Validate email format
     */
    function validateEmail() {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!this.value.trim() || !emailRegex.test(this.value)) {
            this.classList.add('error');
            return false;
        } else {
            this.classList.remove('error');
            return true;
        }
    }
    
    /**
     * Validate phone format
     */
    function validatePhone() {
        const phoneRegex = /^0\d{8,9}$/;
        if (!this.value.trim() || !phoneRegex.test(this.value.replace(/[-\s]/g, ''))) {
            this.classList.add('error');
            return false;
        } else {
            this.classList.remove('error');
            return true;
        }
    }
    
    /**
     * Validate the entire form
     * @returns {boolean} - Form validity
     */
    function validateForm() {
        let isValid = true;
        
        // Validate required inputs
        const requiredInputs = form.querySelectorAll('input[required]');
        requiredInputs.forEach(input => {
            if (input.type === 'email') {
                isValid = validateEmail.call(input) && isValid;
            } else if (input.type === 'tel') {
                isValid = validatePhone.call(input) && isValid;
            } else if (input.type === 'checkbox') {
                if (!input.checked) {
                    input.classList.add('error');
                    isValid = false;
                } else {
                    input.classList.remove('error');
                }
            } else {
                isValid = validateInput.call(input) && isValid;
            }
        });
        
        // Validate package selection
        const selectedPackage = document.querySelector('input[name="package"]:checked');
        if (!selectedPackage) {
            // Show error message
            const errorMessage = document.createElement('div');
            errorMessage.className = 'package-error-message';
            errorMessage.textContent = 'אנא בחרו חבילת אחסון';
            errorMessage.style.color = 'var(--error-color)';
            errorMessage.style.textAlign = 'center';
            errorMessage.style.marginTop = '10px';
            errorMessage.style.fontWeight = 'bold';
            
            // Remove any existing error messages
            const existingError = document.querySelector('.package-error-message');
            if (existingError) {
                existingError.remove();
            }
            
            // Add the error message after the storage options
            const storageOptions = document.querySelector('.storage-options');
            storageOptions.after(errorMessage);
            
            // Scroll to the error message
            errorMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            isValid = false;
        } else {
            // Remove any existing error messages
            const existingError = document.querySelector('.package-error-message');
            if (existingError) {
                existingError.remove();
            }
            
            // Make sure packageSelected is true if a package is checked
            packageSelected = true;
        }
        
        return isValid;
    }
    
    /**
     * Handle form submission
     * @param {Event} e - Submit event
     */
    function handleSubmit(e) {
        e.preventDefault();
        
        // Validate the form first
        if (!validateForm()) {
            return;
        }
        
        // בדיקה שנבחרה חבילה
        if (!packageSelected) {
            showMessage('יש לבחור חבילת אחסון לפני שליחת הטופס', 'error');
            document.querySelector('.storage-options').scrollIntoView({ behavior: 'smooth' });
            return;
        }
        
        // בדיקה שאושרו תנאי השימוש
        const agreeCheckbox = document.getElementById('agree');
        let termsAgreed = false;
        
        if (agreeCheckbox) {
            termsAgreed = agreeCheckbox.checked || 
                          agreeCheckbox.getAttribute('checked') === 'checked' || 
                          agreeCheckbox.hasAttribute('checked');
        }
        
        if (!termsAgreed) {
            showMessage('יש לאשר את תנאי השימוש לפני שליחת הטופס', 'error');
            if (agreeCheckbox) {
                agreeCheckbox.scrollIntoView({ behavior: 'smooth' });
                try {
                    agreeCheckbox.focus();
                } catch (e) {
                    console.warn('Could not focus checkbox:', e);
                }
            }
            return;
        }
        
        // הצגת אנימציית טעינה
        const submitButton = document.getElementById('submitBtn');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'שולח...';
        }
        
        // הצגת אוברליי טעינה
        const loadingOverlay = document.createElement('div');
        loadingOverlay.className = 'loading-overlay';
        loadingOverlay.innerHTML = '<div class="loading-spinner"></div>';
        document.body.appendChild(loadingOverlay);
        
        // שליחת הטופס ישירות ל-PHP
        form.action = 'send-mail.php';
        form.method = 'post';
        
        // הוספת שדה מוסתר להפניה לדף תודה
        addHiddenField('redirect', 'thank_you.html');
        
        // שליחת הטופס
        try {
            form.submit();
        } catch (submitError) {
            console.error('Error submitting form:', submitError);
            
            // הסרת אוברליי טעינה
            loadingOverlay.remove();
            
            // שחזור כפתור השליחה
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'שליחת הטופס';
            }
            
            showError('אירעה שגיאה בשליחת הטופס. אנא צרו קשר עם התמיכה בטלפון 077-300-6306.');
        }
    }
    
    // פונקציה להוספת שדה מוסתר לטופס
    function addHiddenField(name, value) {
        let field = document.getElementById('hidden_' + name);
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.id = 'hidden_' + name;
            field.name = name;
            form.appendChild(field);
        }
        field.value = value;
    }
    
    // פונקציה להצגת הודעות
    function showMessage(message, type = 'error') {
        const messageContainer = document.getElementById('messageContainer');
        if (!messageContainer) return;
        
        messageContainer.textContent = message;
        messageContainer.className = 'message-container ' + type;
        
        // גלילה להודעה
        messageContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        // הסתרת ההודעה אחרי זמן מה אם זו הודעת הצלחה
        if (type === 'success') {
            setTimeout(() => {
                messageContainer.className = 'message-container';
            }, 5000);
        }
    }
    
    // פונקציה להצגת הודעות שגיאה
    function showError(message) {
        showMessage(message, 'error');
    }

    /**
     * Show and update features section based on selected package
     * @param {string} packageId - ID of the selected package (pro60 or pro300)
     */
    function showFeaturesSection(packageId) {
        const featuresSection = document.getElementById('featuresSection');
        if (!featuresSection) return;

        // Update dynamic content based on package
        const playersCount = document.getElementById('playersCount');
        const cloudStorage = document.getElementById('cloudStorage');

        if (packageId === 'pro60') {
            if (playersCount) playersCount.textContent = 'עד 60 שחקנים בכל אירוע';
            if (cloudStorage) cloudStorage.textContent = '1GB';
        } else if (packageId === 'pro300') {
            if (playersCount) playersCount.textContent = 'עד 300 שחקנים בכל אירוע';
            if (cloudStorage) cloudStorage.textContent = '2GB';
        }

        // Show features section with fade-in animation
        featuresSection.style.display = 'block';
        featuresSection.style.opacity = '0';
        featuresSection.style.transition = 'opacity 0.5s ease-in-out';

        setTimeout(() => {
            featuresSection.style.opacity = '1';
            // Scroll to features section smoothly
            setTimeout(() => {
                featuresSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 200);
        }, 100);

        console.log(`מציג חלק תכונות עבור חבילה: ${packageId}`);
    }
}); 