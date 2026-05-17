%global __strip /bin/true

Name: aerospike-php-client
Version: %{?VERSION}
Release: 1
Summary: Aerospike PHP Client Library (native Rust extension)
License: Aerospike, Inc.
Group: Applications/Databases
URL: https://github.com/aerospike/php-client
Source0: aerospike-php-client-%{?VERSION}.tar.gz

%description
The Aerospike PHP client library enables PHP applications to interact with
Aerospike databases. Starting with v2.0.0, the library is a native Rust
extension built on aerospike-client-rust — there is no daemon to run.

%prep
%setup -q -n aerospike-php-client-%{?VERSION}

%build
# Library is pre-built by the CI pipeline; no in-rpm build step.

%install
rm -rf $RPM_BUILD_ROOT
mkdir -p $RPM_BUILD_ROOT%{_libdir}

install -m 755 libaerospike_php.so $RPM_BUILD_ROOT%{_libdir}/libaerospike_php.so
install -m 755 postinst $RPM_BUILD_ROOT%{_libdir}/postinst

%files
%{_libdir}/libaerospike_php.so
%{_libdir}/postinst

%post
%{_libdir}/postinst
echo "Aerospike PHP Client installed successfully."

%postun
rm -f %{_libdir}/libaerospike_php.so
rm -f %{_libdir}/postinst
echo "Aerospike PHP Client removed."

%changelog
