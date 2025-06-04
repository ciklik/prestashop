document.addEventListener('DOMContentLoaded', function() {
    const subscriptionForm = document.querySelector('.ciklik-subscription-options');
    if (!subscriptionForm) return;

    const frequencies = document.querySelectorAll('input[name="ciklik_frequency"]');
    const customizationField = document.querySelector('input[name="ciklik_customization_field"]');
    const addToCartForm = document.querySelector('form[action*="cart"]');

    if (!addToCartForm) return;

    // Gestion du changement de fréquence
    frequencies.forEach(function(frequency) {
        frequency.addEventListener('change', function() {
            const isSubscription = this.checked;
            const frequencyId = this.value;
            
            // Mise à jour du champ de personnalisation
            if (customizationField) {
                const customizationInput = document.createElement('input');
                customizationInput.type = 'hidden';
                customizationInput.name = 'customization_' + customizationField.value;
                customizationInput.value = isSubscription ? frequencyId : '0';
                
                // Supprime l'ancien champ s'il existe
                const oldInput = addToCartForm.querySelector('input[name="customization_' + customizationField.value + '"]');
                if (oldInput) oldInput.remove();
                
                // Ajoute le nouveau champ
                addToCartForm.appendChild(customizationInput);
            }
        });
    });

    // Gestion de la soumission du formulaire
    addToCartForm.addEventListener('submit', function(e) {
        const selectedFrequency = document.querySelector('input[name="ciklik_frequency"]:checked');
        
        if (selectedFrequency && customizationField) {
            const customizationInput = document.createElement('input');
            customizationInput.type = 'hidden';
            customizationInput.name = 'customization_' + customizationField.value;
            customizationInput.value = selectedFrequency.value;
            
            // Supprime l'ancien champ s'il existe
            const oldInput = addToCartForm.querySelector('input[name="customization_' + customizationField.value + '"]');
            if (oldInput) oldInput.remove();
            
            // Ajoute le nouveau champ
            addToCartForm.appendChild(customizationInput);
        }
    });
});