%global __strip /bin/true

# The payload is a binary built outside the rpm (%%build is a no-op), so there is
# no source to attach debug symbols to. Without this, an rpmbuild whose macros
# enable debuginfo subpackages (any EL host, via redhat-rpm-config) aborts with
# "Empty %%files file ... debugsourcefiles.list".
%global debug_package %{nil}

# PHP release the .so was compiled against. The extension is linked against that
# release's ZEND_MODULE_API_NO and will not load under any other PHP minor, so
# the dependency below is pinned to exactly this minor. CI passes the value with
# --define "PHP_VERSION 8.3"; the fallback only keeps a manual rpmbuild working.
%global php_ver %{?PHP_VERSION}%{!?PHP_VERSION:8.3}
%global php_ver_next %(echo %{php_ver} | awk -F. '{print $1"."$2+1}')

# Package-private helper directory. The maintainer scripts used to be installed
# as %%{_libdir}/postinst — a generic name in a directory shared with every other
# package on the system.
%global helperdir %{_libexecdir}/aerospike-php-client

Name: aerospike-php-client
Version: %{?VERSION}
Release: 1.php%{php_ver}
Summary: Aerospike PHP Client Library (native Rust extension)
License: Aerospike, Inc.
Group: Applications/Databases
URL: https://github.com/aerospike/php-client
Source0: aerospike-php-client-%{?VERSION}.tar.gz

Requires: php(language) >= %{php_ver}
Requires: php(language) < %{php_ver_next}

%description
The Aerospike PHP client library enables PHP applications to interact with
Aerospike databases. Starting with v2.0.0, the library is a native Rust
extension built on aerospike-client-rust — there is no daemon to run.

This build targets PHP %{php_ver}.

%prep
%setup -q -n aerospike-php-client-%{?VERSION}

%build
# Library is pre-built by the CI pipeline; no in-rpm build step.

%install
rm -rf $RPM_BUILD_ROOT
mkdir -p $RPM_BUILD_ROOT%{_libdir}
mkdir -p $RPM_BUILD_ROOT%{helperdir}

install -m 755 libaerospike_php.so $RPM_BUILD_ROOT%{_libdir}/libaerospike_php.so
install -m 755 postinst $RPM_BUILD_ROOT%{helperdir}/postinst
install -m 755 prerm $RPM_BUILD_ROOT%{helperdir}/prerm

%files
%{_libdir}/libaerospike_php.so
%dir %{helperdir}
%{helperdir}/postinst
%{helperdir}/prerm

%post
# $1 is 1 on a fresh install and 2 on an upgrade; the extension has to be copied
# into PHP's extension_dir in both cases.
%{helperdir}/postinst
echo "Aerospike PHP Client installed successfully."

%preun
# Runs before the payload is deleted, so the helper is still on disk. It guards
# itself on $1 (0 = final erase, 1 = upgrade) and only cleans up on an erase.
%{helperdir}/prerm "$1" || :

%postun
# rpm removes everything listed in %%files on its own — deleting those paths here
# would, on `rpm -U`, wipe the files the new version's %%post has just installed
# (upgrade order is: new %%post, then old %%postun with $1=1).
[ "$1" = 0 ] || exit 0
echo "Aerospike PHP Client removed."

%changelog
