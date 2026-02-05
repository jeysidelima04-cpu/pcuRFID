document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('profileUpload').addEventListener('change', async function(event) {
        const file = event.target.files[0];
        if (!file) return;

        // Create FormData
        const form = document.getElementById('profileForm');
        const formData = new FormData(form);

        try {
            const response = await fetch('upload_profile.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            const result = await response.json();
            if (result.success) {
                // Update profile picture with new image
                document.getElementById('profilePicture').src = result.profile_picture_url;
                // Show success message
                alert('Profile picture updated successfully!');
            } else {
                alert(result.message || 'Failed to upload profile picture');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred while uploading the profile picture');
        }
    });
});