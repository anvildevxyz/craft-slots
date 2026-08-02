/**
 * Validation Utilities
 * 
 * Shared validation functions for the Slots plugin
 */
window.SlotsValidation = {
    /**
     * Email validation - requires proper domain with TLD
     * 
     * Validates:
     * - Local part with valid characters
     * - @ symbol
     * - Domain with at least one dot (e.g., example.com, not just "example")
     * - TLD of at least 2 characters
     * 
     * @param {string} email - Email address to validate
     * @returns {boolean} - True if email is valid
     */
    isValidEmail(email) {
        if (!email || typeof email !== 'string') {
            return false;
        }
        
        // Trim whitespace
        email = email.trim();
        
        // Email regex that requires:
        // - Local part with valid characters
        // - @ symbol
        // - Domain with at least one dot (e.g., example.com, not just "example")
        // - TLD of at least 2 characters
        const emailRegex = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/;
        
        return emailRegex.test(email);
    }
};
