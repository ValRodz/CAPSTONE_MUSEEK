// Diagnostic script to check the current state of price slider and favorites filter
// Run this in the browser console on the main page

console.log('=== MAIN PAGE DIAGNOSTIC ===');

// Check authentication status
const isAuth = typeof window.isAuthenticated !== 'undefined' ? window.isAuthenticated : 
               document.querySelector('.favorite-btn') !== null;
console.log('Authentication Status:', isAuth ? 'Authenticated' : 'Guest');

// Check price slider elements
const priceElements = {
    minInput: document.getElementById('filterPriceMin'),
    maxInput: document.getElementById('filterPriceMax'),
    rangeFill: document.getElementById('filtersRangeFill'),
    minBubble: document.getElementById('filterPriceMinBubble'),
    maxBubble: document.getElementById('filterPriceMaxBubble'),
    minLabel: document.getElementById('priceMinLabel'),
    maxLabel: document.getElementById('priceMaxLabel')
};

console.log('Price Slider Elements:');
Object.entries(priceElements).forEach(([name, element]) => {
    console.log(`  ${name}: ${element ? 'FOUND' : 'NOT FOUND'}`);
});

// Check current values
if (priceElements.minInput && priceElements.maxInput) {
    console.log('Current Price Values:');
    console.log(`  Min: ₱${priceElements.minInput.value}`);
    console.log(`  Max: ₱${priceElements.maxInput.value}`);
}

// Check favorites filter
const favoritesButton = document.querySelector('[data-filter="favorites"]');
const allButton = document.querySelector('[data-filter="all"]');
console.log('Favorites Filter Button:', favoritesButton ? 'FOUND' : 'NOT FOUND');
console.log('All Filter Button:', allButton ? 'FOUND' : 'NOT FOUND');

// Check if updatePriceUI function exists
console.log('updatePriceUI Function:', typeof window.updatePriceUI === 'function' ? 'EXISTS' : 'NOT FOUND');
console.log('updatePriceSliderGlobal Function:', typeof window.updatePriceSliderGlobal === 'function' ? 'EXISTS' : 'NOT FOUND');

// Test manual price slider update
if (priceElements.minInput && priceElements.maxInput) {
    console.log('Testing manual price slider update...');
    
    // Set test values
    priceElements.minInput.value = '1000';
    priceElements.maxInput.value = '3000';
    
    // Try to trigger update
    if (typeof window.updatePriceSliderGlobal === 'function') {
        window.updatePriceSliderGlobal();
        console.log('✅ Manual update triggered via updatePriceSliderGlobal');
    } else {
        console.log('❌ updatePriceSliderGlobal not available');
    }
    
    // Check if values updated
    setTimeout(() => {
        console.log('After update - Min:', priceElements.minInput.value, 'Max:', priceElements.maxInput.value);
        
        // Check visual elements
        if (priceElements.rangeFill) {
            console.log('Range fill style:', {
                left: priceElements.rangeFill.style.left,
                width: priceElements.rangeFill.style.width
            });
        }
        
        if (priceElements.minBubble) {
            console.log('Min bubble style:', {
                left: priceElements.minBubble.style.left
            });
        }
        
        if (priceElements.maxBubble) {
            console.log('Max bubble style:', {
                left: priceElements.maxBubble.style.left
            });
        }
    }, 1000);
}

// Test event listeners
if (priceElements.minInput) {
    console.log('Testing event listeners...');
    
    // Create a test event
    const testEvent = new Event('input', { bubbles: true });
    
    // Add a temporary listener to see if events are working
    const tempListener = () => {
        console.log('✅ Min input event listener triggered');
        priceElements.minInput.removeEventListener('input', tempListener);
    };
    
    priceElements.minInput.addEventListener('input', tempListener);
    priceElements.minInput.dispatchEvent(testEvent);
}

console.log('=== DIAGNOSTIC COMPLETE ===');

// Provide manual test commands
console.log('\nMANUAL TEST COMMANDS:');
console.log('1. Test debug function: debugPriceSlider()');
console.log('2. Check price elements: document.getElementById("filterPriceMin")');
console.log('3. Manually trigger update: window.updatePriceSliderGlobal()');
console.log('4. Check favorites button: document.querySelector("[data-filter=\\"favorites\\"]")');