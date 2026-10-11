/**
 * SMM Panel - Web Installer JavaScript
 */

document.addEventListener('DOMContentLoaded', () => {
    const testDbBtn = document.getElementById('btnTestDb');
    if (testDbBtn) {
        testDbBtn.addEventListener('click', async () => {
            const host = document.getElementById('db_host').value.trim();
            const name = document.getElementById('db_name').value.trim();
            const user = document.getElementById('db_user').value.trim();
            const pass = document.getElementById('db_pass').value;
            const statusDiv = document.getElementById('dbTestResult');

            if (!name || !user) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Missing Details',
                        text: 'Please enter database name and username.'
                    });
                } else {
                    alert('Please enter database name and username.');
                }
                return;
            }

            testDbBtn.disabled = true;
            testDbBtn.innerHTML = 'Testing connection...';
            statusDiv.className = 'mt-3 p-3 text-xs rounded-xl bg-slate-100 text-slate-700';
            statusDiv.innerHTML = 'Connecting to database server...';

            try {
                const formData = new FormData();
                formData.append('action', 'test_connection');
                formData.append('db_host', host);
                formData.append('db_name', name);
                formData.append('db_user', user);
                formData.append('db_pass', pass);

                const res = await fetch('database.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    statusDiv.className = 'mt-3 p-3 text-xs rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold';
                    statusDiv.innerHTML = '✓ Connection successful! Database is reachable.';
                    const nextBtn = document.getElementById('btnDbNext');
                    if (nextBtn) nextBtn.disabled = false;

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Connected!',
                            text: 'Database connection established successfully.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    }
                } else {
                    statusDiv.className = 'mt-3 p-3 text-xs rounded-xl bg-rose-50 text-rose-700 border border-rose-200 font-bold';
                    statusDiv.innerHTML = '✕ Connection failed: ' + (data.message || 'Unknown error');
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Connection Failed',
                            text: data.message || 'Could not connect to database.'
                        });
                    }
                }
            } catch (err) {
                statusDiv.className = 'mt-3 p-3 text-xs rounded-xl bg-rose-50 text-rose-700 border border-rose-200 font-bold';
                statusDiv.innerHTML = '✕ Connection error: ' + err.message;
            } finally {
                testDbBtn.disabled = false;
                testDbBtn.innerHTML = 'Test Database Connection';
            }
        });
    }
});
