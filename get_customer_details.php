<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#customersTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search customers:",
            lengthMenu: "Show _MENU_ customers",
            info: "Showing _START_ to _END_ of _TOTAL_ customers",
            emptyTable: "No customers available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: -1 }
            <?php endif; ?>
        ],
        // Ensure modals work with DataTable
        drawCallback: function() {
            // Re-initialize any Bootstrap components if needed
            if (typeof bootstrap !== 'undefined') {
                // Nothing needed here as modals are initialized via data-bs-toggle
            }
        }
    });

    // Handle modal cleanup to prevent backdrop issues
    $('.modal').on('hidden.bs.modal', function () {
        $('body').removeClass('modal-open');
        $('.modal-backdrop').remove();
        $(this).data('bs.modal', null);
    });

    // Phone number validation
    document.querySelectorAll('input[name="phone"]').forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9+]/g, '');
        });
    });

    // GST number validation
    document.querySelectorAll('input[name="gst_number"]').forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    });

    // Form validation for add customer
    document.querySelector('#addCustomerModal form')?.addEventListener('submit', function(e) {
        const phone = this.querySelector('input[name="phone"]').value;
        const email = this.querySelector('input[name="email"]').value;
        const gst = this.querySelector('input[name="gst_number"]').value;
        
        if (phone && !/^[0-9+]{10,15}$/.test(phone)) {
            e.preventDefault();
            alert('Please enter a valid phone number (10-15 digits, can start with +)');
            return false;
        }
        
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            e.preventDefault();
            alert('Please enter a valid email address');
            return false;
        }
        
        if (gst && gst.length !== 15) {
            e.preventDefault();
            alert('GST number must be 15 characters long');
            return false;
        }
    });
});

// View customer details - Improved version
function viewCustomer(customerId) {
    // Get the modal element
    const modalElement = document.getElementById('viewCustomerModal');
    
    // Create new modal instance
    const modal = new bootstrap.Modal(modalElement);
    
    // Update modal title
    document.getElementById('viewCustomerTitle').textContent = 'Loading Customer Details...';
    
    // Show loading state
    document.getElementById('viewCustomerBody').innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Fetching customer details...</p>
        </div>
    `;
    
    // Show the modal
    modal.show();
    
    // Load customer details via AJAX
    fetch('get_customer_details.php?id=' + customerId)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text();
        })
        .then(html => {
            document.getElementById('viewCustomerBody').innerHTML = html;
            document.getElementById('viewCustomerTitle').textContent = 'Customer Details';
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('viewCustomerBody').innerHTML = `
                <div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Failed to load customer details. Please try again.
                </div>
            `;
        });
}

// Function to handle edit modal - ensure it works properly
function editCustomer(customerId) {
    const modalId = 'editCustomerModal' + customerId;
    const modalElement = document.getElementById(modalId);
    
    if (modalElement) {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    }
}

// Add event listeners for all edit buttons to ensure they work
document.addEventListener('DOMContentLoaded', function() {
    // Force Bootstrap to initialize all modals
    if (typeof bootstrap !== 'undefined') {
        // Find all modals and ensure they're properly initialized
        document.querySelectorAll('.modal[id^="editCustomerModal"]').forEach(modal => {
            // Bootstrap will handle initialization via data-bs-toggle
            // This just ensures the modal exists
            modal.addEventListener('show.bs.modal', function() {
                // Optional: Any pre-modal show logic
            });
        });
    }
});

// Helper function to format phone numbers if needed
function formatPhone(phone) {
    if (!phone) return '-';
    if (phone.length === 10) {
        return phone.substr(0, 5) + ' ' + phone.substr(5);
    }
    return phone;
}
</script>